# ============================================================
# LTI 1.3 Tool Provider endpoints (pylti1p3)
# Moodle acts as Platform; this service acts as Tool Provider
# ============================================================

import logging
import os
from typing import Optional

from fastapi import APIRouter, Request, HTTPException, Form
from fastapi.responses import RedirectResponse, JSONResponse
from pydantic import BaseModel

from config import get_settings

logger = logging.getLogger(__name__)
router = APIRouter(prefix="/lti", tags=["lti"])
settings = get_settings()

# Lazy-init LTI tool to avoid import errors when LTI is not configured
_lti_tool = None


def _get_lti_tool():
    global _lti_tool
    if _lti_tool is not None:
        return _lti_tool

    if not settings.lti_enabled:
        return None

    try:
        from pylti1p3.tool_config import ToolConfJsonFile
        from pylti1p3.registration import Registration
        from pylti1p3.tool_config import ToolConfAbstract
        from pylti1p3.contrib.fastapi import FastAPIMessageLaunch, FastAPIOIDCLogin
        from pylti1p3.tool_config import ToolConfDict

        # Build tool config from environment settings
        tool_conf = ToolConfDict({
            "https://purl.imsglobal.org/spec/lti/claim/deployment_id": settings.lti_deployment_id,
            "https://purl.imsglobal.org/spec/lti/claim/target_link_uri": settings.lti_target_link_uri,
            "https://purl.imsglobal.org/spec/lti/claim/custom": {},
        })

        _lti_tool = {
            "tool_conf": tool_conf,
            "client_id": settings.lti_client_id,
            "deployment_id": settings.lti_deployment_id,
            "issuer": settings.lti_platform_issuer,
            "auth_login_url": settings.lti_auth_login_url,
            "auth_token_url": settings.lti_auth_token_url,
            "key_set_url": settings.lti_key_set_url,
        }
        return _lti_tool
    except ImportError:
        logger.warning("pylti1p3 not installed — LTI endpoints disabled")
        return None


class LTIConfigStatus(BaseModel):
    enabled: bool
    client_id: Optional[str] = None
    deployment_id: Optional[str] = None
    target_link_uri: Optional[str] = None


@router.get("/config", response_model=LTIConfigStatus)
async def lti_config_status():
    """Return LTI configuration status (for admin verification)."""
    return LTIConfigStatus(
        enabled=settings.lti_enabled,
        client_id=settings.lti_client_id or None,
        deployment_id=settings.lti_deployment_id or None,
        target_link_uri=settings.lti_target_link_uri or None,
    )


@router.post("/login")
async def lti_login_initiation(
    request: Request,
    iss: str = Form(...),
    login_hint: str = Form(...),
    target_link_uri: str = Form(...),
    lti_message_hint: Optional[str] = Form(None),
):
    """
    OIDC login initiation — Moodle Platform sends the user here first.
    Redirects back to Moodle's auth endpoint with an OIDC request.
    """
    if not settings.lti_enabled:
        raise HTTPException(status_code=503, detail="LTI is not configured")

    try:
        from pylti1p3.contrib.fastapi import FastAPIOIDCLogin

        tool = _get_lti_tool()
        if not tool:
            raise HTTPException(status_code=503, detail="LTI tool not initialized")

        oidc_login = FastAPIOIDCLogin(request, tool["tool_conf"])
        return oidc_login.redirect(
            iss=iss,
            login_hint=login_hint,
            target_link_uri=target_link_uri,
            lti_message_hint=lti_message_hint,
        )
    except ImportError:
        raise HTTPException(status_code=503, detail="pylti1p3 not installed")
    except Exception as e:
        logger.error(f"LTI login error: {e}")
        raise HTTPException(status_code=400, detail=str(e))


@router.post("/launch")
async def lti_launch(request: Request):
    """
    LTI 1.3 launch — validates JWT from Moodle and extracts context.
    Returns launch context (user_id, roles, course_id) for the frontend dashboard.
    """
    if not settings.lti_enabled:
        raise HTTPException(status_code=503, detail="LTI is not configured")

    try:
        from pylti1p3.contrib.fastapi import FastAPIMessageLaunch

        tool = _get_lti_tool()
        if not tool:
            raise HTTPException(status_code=503, detail="LTI tool not initialized")

        message_launch = FastAPIMessageLaunch(request, tool["tool_conf"])
        launch_data = message_launch.get_launch_data()

        custom = launch_data.get("https://purl.imsglobal.org/spec/lti/claim/custom", {})
        context = launch_data.get("https://purl.imsglobal.org/spec/lti/claim/context", {})
        roles = launch_data.get("https://purl.imsglobal.org/spec/lti/claim/roles", [])

        return JSONResponse({
            "success": True,
            "user_id": custom.get("user_id") or launch_data.get("sub"),
            "roles": roles,
            "context_id": context.get("id"),
            "context_label": context.get("label"),
            "course_id": custom.get("course_id") or context.get("id"),
            "launch_data": {
                "name": launch_data.get("name"),
                "email": launch_data.get("email"),
            },
        })
    except ImportError:
        raise HTTPException(status_code=503, detail="pylti1p3 not installed")
    except Exception as e:
        logger.error(f"LTI launch error: {e}")
        raise HTTPException(status_code=401, detail=f"LTI launch validation failed: {e}")


@router.get("/jwks")
async def lti_jwks():
    """Public JWKS endpoint for Moodle Platform registration."""
    if not settings.lti_enabled:
        raise HTTPException(status_code=503, detail="LTI is not configured")

    key_path = settings.lti_private_key_path
    if not key_path or not os.path.isfile(key_path):
        raise HTTPException(status_code=503, detail="LTI private key not configured")

    try:
        from pylti1p3.tool_config import ToolConfJsonFile
        # Return JWKS from configured key — admin must place key.pem in lti_keys/
        from cryptography.hazmat.primitives.serialization import load_pem_private_key
        from cryptography.hazmat.backends import default_backend
        import json
        import base64

        with open(key_path, "rb") as f:
            private_key = load_pem_private_key(f.read(), password=None, backend=default_backend())

        public_key = private_key.public_key()
        numbers = public_key.public_numbers()

        def _int_to_base64url(val):
            import struct
            byte_length = (val.bit_length() + 7) // 8
            return base64.urlsafe_b64encode(val.to_bytes(byte_length, "big")).rstrip(b"=").decode()

        jwk = {
            "kty": "RSA",
            "alg": "RS256",
            "use": "sig",
            "kid": settings.lti_key_id or "umat-ai-key-1",
            "n": _int_to_base64url(numbers.n),
            "e": _int_to_base64url(numbers.e),
        }
        return JSONResponse({"keys": [jwk]})
    except Exception as e:
        logger.error(f"JWKS error: {e}")
        raise HTTPException(status_code=500, detail="Failed to generate JWKS")
