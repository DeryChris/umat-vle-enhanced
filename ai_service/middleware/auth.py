# ============================================================
# Bearer token + JWT authentication
# Accepts static Bearer token OR HS256 JWT signed with AI_SERVICE_TOKEN
# ============================================================

import jwt
from fastapi import HTTPException, Security, status
from fastapi.security import HTTPAuthorizationCredentials, HTTPBearer
from config import get_settings

security = HTTPBearer()
settings = get_settings()


def _verify_jwt(token: str) -> bool:
    try:
        jwt.decode(
            token,
            settings.ai_service_token,
            algorithms=["HS256"],
            options={"require": ["exp", "iat"]},
        )
        return True
    except jwt.PyJWTError:
        return False


def verify_token(credentials: HTTPAuthorizationCredentials = Security(security)):
    token = credentials.credentials
    if token == settings.ai_service_token:
        return token
    if _verify_jwt(token):
        return token
    raise HTTPException(
        status_code=status.HTTP_401_UNAUTHORIZED,
        detail="Invalid or missing authentication token",
        headers={"WWW-Authenticate": "Bearer"},
    )
