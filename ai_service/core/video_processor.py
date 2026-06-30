from PIL import Image, ImageDraw, ImageFont
import edge_tts
import asyncio
import subprocess
import os
import uuid
import json
import logging
from pathlib import Path
from config import get_settings
from core.llm_processor import LLMProcessor
from core.document_loader import DocumentLoader

settings = get_settings()
logger = logging.getLogger(__name__)

VIDEO_DIR = Path(settings.upload_dir) / "videos"
SLIDE_DIR = Path(settings.upload_dir) / "slides"
FONT_PATH = None

SLIDE_W, SLIDE_H = 1920, 1080
ACCENT = (0, 107, 47)
BG = (248, 250, 252)
WHITE = (255, 255, 255)
DARK = (30, 35, 40)
GRAY = (100, 110, 120)
LIGHT_GRAY = (220, 225, 230)


def _find_font():
    global FONT_PATH
    if FONT_PATH:
        return FONT_PATH
    candidates = [
        "C:/Windows/Fonts/inter/Inter-VariableFont.ttf",
        "C:/Windows/Fonts/Inter-VariableFont.ttf",
        "C:/Windows/Fonts/Inter.ttf",
        "C:/Windows/Fonts/arial.ttf",
        "C:/Windows/Fonts/segoeui.ttf",
        "C:/Windows/Fonts/Calibri.ttf",
        "/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf",
        "/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf",
    ]
    for p in candidates:
        if os.path.exists(p):
            FONT_PATH = p
            return p
    FONT_PATH = None
    return None


def _font(size, bold=False):
    path = _find_font()
    try:
        if bold and path:
            return ImageFont.truetype(path.replace(".ttf", "-Bold.ttf"), size)
        return ImageFont.truetype(path, size) if path else ImageFont.load_default()
    except (OSError, IOError):
        return ImageFont.load_default()


def _draw_gradient_header(draw, w, h, color_top, color_bottom):
    """Draw a smooth gradient from color_top to color_bottom."""
    for y in range(h):
        r = int(color_top[0] + (color_bottom[0] - color_top[0]) * y / h)
        g = int(color_top[1] + (color_bottom[1] - color_top[1]) * y / h)
        b = int(color_top[2] + (color_bottom[2] - color_top[2]) * y / h)
        draw.line([(0, y), (w, y)], fill=(r, g, b))


ACCENT_LIGHT = (0, 140, 65)
ACCENT_DARK = (0, 80, 35)


def render_slide(title: str, bullets: list[str], slide_num: int, total: int) -> str:
    img = Image.new("RGB", (SLIDE_W, SLIDE_H), BG)
    draw = ImageDraw.Draw(img)

    # Gradient header.
    header_h = 130
    _draw_gradient_header(draw, SLIDE_W, header_h, ACCENT_DARK, ACCENT_LIGHT)

    # Bottom accent line under header.
    draw.rectangle([(0, header_h), (SLIDE_W, header_h + 4)], fill=ACCENT)

    # Title text.
    try:
        title_font = _font(44, bold=True)
        title_y = (header_h - 50) / 2
        draw.text((60, title_y), title, fill=WHITE, font=title_font)
    except Exception:
        draw.text((60, 30), title, fill=WHITE)

    # Decorative bullet accent line on left side.
    draw.rectangle([(40, header_h + 50), (44, SLIDE_H - 80)], fill=ACCENT)

    y = header_h + 60
    body_font = _font(28)
    bullet_icon = "\u2022  "  # bullet character

    for b in bullets:
        if y > SLIDE_H - 120:
            break
        text_with_bullet = bullet_icon + b
        wrapped = _wrap_text(draw, text_with_bullet, body_font, SLIDE_W - 180)
        for line in wrapped:
            if y > SLIDE_H - 120:
                break
            draw.text((80, y), line, fill=DARK, font=body_font)
            y += 40
        y += 14

    # Bottom bar.
    draw.rectangle([(0, SLIDE_H - 60), (SLIDE_W, SLIDE_H)], fill=(240, 242, 246))

    # Progress bar.
    bar_x, bar_y, bar_w, bar_h = 50, SLIDE_H - 42, SLIDE_W - 100, 8
    draw.rectangle([(bar_x, bar_y), (bar_x + bar_w, bar_y + bar_h)], fill=LIGHT_GRAY)
    fill_w = int(bar_w * slide_num / total) if total > 0 else bar_w
    draw.rectangle([(bar_x, bar_y), (bar_x + fill_w, bar_y + bar_h)], fill=ACCENT)

    # Slide number.
    footer_font = _font(18)
    footer_text = f"{slide_num} / {total}"
    try:
        fw = draw.textlength(footer_text, font=footer_font)
    except Exception:
        fw = len(footer_text) * 10
    draw.text((SLIDE_W - fw - 50, SLIDE_H - 52), footer_text, fill=GRAY, font=footer_font)

    SLIDE_DIR.mkdir(parents=True, exist_ok=True)
    path = str(SLIDE_DIR / f"{uuid.uuid4()}.png")
    img.save(path, "PNG")
    return path


def _wrap_text(draw, text, font, max_width):
    try:
        words = text.split()
        lines = []
        current = ""
        for w in words:
            test = current + " " + w if current else w
            try:
                tw = draw.textlength(test, font=font)
            except Exception:
                tw = len(test) * 15
            if tw <= max_width:
                current = test
            else:
                if current:
                    lines.append(current)
                current = w
        if current:
            lines.append(current)
        return lines or [text]
    except Exception:
        return [text]


async def generate_narration(text: str) -> str:
    path = str(Path(settings.upload_dir) / f"{uuid.uuid4()}.mp3")
    communicate = edge_tts.Communicate(text, voice="en-GB-SoniaNeural", rate="+10%")
    await communicate.save(path)
    return path


CROSSFADE_DURATION = 0.5


async def compile_video(slide_paths: list[str], audio_paths: list[str], output_path: str):
    if len(slide_paths) != len(audio_paths):
        raise ValueError("slide/audio count mismatch")

    durations = []
    for ap in audio_paths:
        result = subprocess.run(
            ["ffprobe", "-v", "error", "-show_entries", "format=duration",
             "-of", "default=noprint_wrappers=1:nokey=1", ap],
            capture_output=True, text=True
        )
        try:
            durations.append(float(result.stdout.strip()))
        except (ValueError, TypeError):
            durations.append(5.0)

    n = len(slide_paths)
    inputs = []
    trim_parts = []
    for i, sp in enumerate(slide_paths):
        inputs.extend(["-loop", "1", "-i", sp])
        dur = durations[i]
        trim_parts.append(f"[{i}:v]trim=duration={dur},setpts=PTS-STARTPTS[v{i}]")

    # Apply fade-in at start and fade-out at end for each slide segment.
    fade_parts = []
    for i in range(n):
        dur = durations[i]
        fade_parts.append(f"[v{i}]fade=t=in:st=0:d={CROSSFADE_DURATION},fade=t=out:st={dur - CROSSFADE_DURATION}:d={CROSSFADE_DURATION}[vf{i}]")

    # Concatenate all faded video segments.
    all_v = "".join(f"[vf{i}]" for i in range(n))
    vconcat = f"{all_v}concat=n={n}:v=1:a=0[vid]"

    # Audio inputs.
    audio_inputs = []
    for i in range(n):
        audio_inputs.extend(["-i", audio_paths[i]])

    # Mix all audio tracks into one, using the longest duration.
    amix_label = f"amix=inputs={n}:duration=first"
    all_a = "".join(f"[{n + i}:a]" for i in range(n))
    aconcat = f"{all_a}{amix_label}[aud]"

    all_filters = trim_parts + fade_parts + [vconcat, aconcat]

    cmd = [
        "ffmpeg", "-y",
        *inputs,
        *audio_inputs,
        "-filter_complex",
        ";".join(all_filters),
        "-map", "[vid]",
        "-map", "[aud]",
        "-c:v", "libx264", "-preset", "medium", "-crf", "23",
        "-c:a", "aac", "-b:a", "128k",
        "-shortest",
        "-pix_fmt", "yuv420p",
        output_path,
    ]

    logger.info(f"FFmpeg command: ffmpeg -y ...")
    result = subprocess.run(cmd, capture_output=True, text=True)
    if result.returncode != 0:
        logger.error(f"FFmpeg stderr: {result.stderr[:1000]}")
        raise RuntimeError(f"FFmpeg video compile failed: {result.stderr[:500]}")
    logger.info(f"Video compiled: {output_path}")


def cleanup_files(*paths):
    for p in paths:
        try:
            if p and os.path.exists(p):
                os.remove(p)
        except OSError:
            pass


def generate_slide_content(text: str, title: str) -> list[dict]:
    llm = LLMProcessor()
    prompt = f"""You are an expert university lecturer creating a comprehensive video lecture. You have been given a course material as a foundation, but you MUST expand with your broader knowledge of the subject to create a thorough, engaging lesson.

Course material title: {title}
Material content:
{text[:15000]}

Structure the lecture like this:
1. OPENING SLIDE (1 slide): Lesson title with subtitle, "What You Will Learn" — list 3-4 learning objectives
2. CONTENT SLIDES (5-10 slides): Core content covering all major topics. Expand beyond the material using your general knowledge. Include examples, real-world applications, and clear explanations. Use a teaching tone — as if explaining to a class.
3. CONCLUSION SLIDE (1 slide): "Key Takeaways" — summarize 3-4 most important points the student should remember

Total: 7-12 slides depending on the breadth of the topic.

For each slide, output a JSON object with:
- "title": short, clear slide heading (4-8 words)
- "bullets": array of 3-5 bullet points (concise, scannable, max 12 words each)
- "narration": complete spoken paragraph for this slide (4-5 sentences, natural conversational teaching tone)

The narration should sound like a real lecturer explaining — not reading bullets verbatim. Each narration paragraph should flow naturally into the next.

Respond with ONLY a valid JSON array of slide objects, nothing else.
"""
    raw = llm._invoke(prompt, temperature=0.3, max_chars=12000)
    raw = raw.strip()
    if raw.startswith("```json"):
        raw = raw[7:]
    if raw.startswith("```"):
        raw = raw[3:]
    if raw.endswith("```"):
        raw = raw[:-3]
    raw = raw.strip()
    try:
        slides = json.loads(raw)
        if isinstance(slides, list) and len(slides) > 0:
            return slides
    except (json.JSONDecodeError, TypeError) as e:
        logger.warning(f"LLM JSON parse failed: {e}. Raw: {raw[:200]}")
    # Fallback: create minimal slides based on material title.
    return [
        {"title": f"Introduction to {title}", "bullets": ["Overview of key concepts", "Learning objectives covered"],
         "narration": f"In this lesson, we will explore {title}. By the end, you will have a solid understanding of the core concepts."},
        {"title": f"Key Concepts in {title}", "bullets": ["Main ideas and principles", "Real-world applications", "Important terminology"],
         "narration": f"Let us dive into the key concepts of {title}. These ideas form the foundation of our understanding."},
        {"title": "Summary & Key Takeaways", "bullets": ["Review of main points covered", "Practical applications", "Further reading suggestions"],
         "narration": f"To summarise, we have covered the essential aspects of {title}. I encourage you to explore these concepts further in your own study."}
    ]


def load_material_text(material_id: int, course_id: int, file_path: str, filename: str) -> str:
    loader = DocumentLoader()
    try:
        text = loader.load_file(file_path)
        if not text or len(text.strip()) < 20:
            fallback = f"Course material: {filename}. Material ID: {material_id}."
            return fallback
        return text
    except Exception as e:
        logger.warning(f"Could not load material {material_id}: {e}")
        return f"Course material: {filename}. Material ID: {material_id}."


async def generate_video_pipeline(
    material_id: int,
    course_id: int,
    file_path: str,
    filename: str,
    progress_callback=None
) -> str:
    VIDEO_DIR.mkdir(parents=True, exist_ok=True)

    if progress_callback:
        progress_callback(5, "Loading material text")

    text = load_material_text(material_id, course_id, file_path, filename)

    if progress_callback:
        progress_callback(15, "Generating slide content with AI")

    slides = generate_slide_content(text, filename)

    slide_paths = []
    audio_paths = []
    total = len(slides)

    try:
        for i, slide in enumerate(slides):
            if progress_callback:
                pct = 15 + int((i / total) * 45)
                progress_callback(pct, f"Rendering slide {i+1}/{total}")

            slide_path = render_slide(
                slide.get("title", filename),
                slide.get("bullets", ["Key concept"]),
                i + 1, total
            )
            slide_paths.append(slide_path)

            if progress_callback:
                progress_callback(15 + int(((i + 0.5) / total) * 45),
                                  f"Generating narration for slide {i+1}/{total}")

            audio_path = await generate_narration(slide.get("narration", f"Slide {i+1}"))
            audio_paths.append(audio_path)

        if progress_callback:
            progress_callback(65, "Compiling video with FFmpeg")

        output_path = str(VIDEO_DIR / f"video_{uuid.uuid4()}.mp4")
        await compile_video(slide_paths, audio_paths, output_path)

        if progress_callback:
            progress_callback(95, "Finalizing")

        return output_path

    except Exception as e:
        raise
    finally:
        cleanup_files(*slide_paths, *audio_paths)
