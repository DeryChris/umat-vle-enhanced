# ============================================================
# SQLAlchemy models for the AI service's own PostgreSQL database
#
# NOTE: declarative_base() from sqlalchemy.ext.declarative is deprecated
# in SQLAlchemy 2.0+. Use `from sqlalchemy.orm import DeclarativeBase`
# in newer projects.
# ============================================================

from sqlalchemy import create_engine, Column, Integer, String, Text, Boolean, DateTime, Float
from sqlalchemy.ext.declarative import declarative_base  # legacy; works in 2.0 with warning
from sqlalchemy.orm import sessionmaker
from datetime import datetime
from config import get_settings

settings = get_settings()

engine = create_engine(
    settings.database_url,
    pool_pre_ping=True,
    pool_size=10,
    max_overflow=20,
)

SessionLocal = sessionmaker(autocommit=False, autoflush=False, bind=engine)
Base = declarative_base()


class ProcessingJob(Base):
    __tablename__ = "processing_jobs"

    id            = Column(Integer, primary_key=True, index=True)
    job_id        = Column(String(100), unique=True, index=True, nullable=False)
    session_id    = Column(String(100), nullable=False)
    course_id     = Column(Integer, nullable=False)
    recording_url = Column(Text, nullable=True)
    status        = Column(String(30), default="queued")  # queued|processing|completed|failed
    transcript    = Column(Text, nullable=True)
    error_message = Column(Text, nullable=True)
    created_at    = Column(DateTime, default=datetime.utcnow)
    completed_at  = Column(DateTime, nullable=True)


class IndexedDocument(Base):
    __tablename__ = "indexed_documents"

    id              = Column(Integer, primary_key=True, index=True)
    material_id     = Column(Integer, nullable=False)
    course_id       = Column(Integer, nullable=False)
    filename        = Column(String(255), nullable=False)
    chunk_count     = Column(Integer, default=0)
    collection_name = Column(String(100), nullable=False)
    indexed_at      = Column(DateTime, default=datetime.utcnow)


class ChatLog(Base):
    __tablename__ = "chat_logs"

    id               = Column(Integer, primary_key=True, index=True)
    user_id          = Column(Integer, nullable=False)
    course_id        = Column(Integer, nullable=False)
    session_key      = Column(String(100), nullable=True, index=True)
    question         = Column(Text, nullable=False)
    answer           = Column(Text, nullable=True)
    sources          = Column(Text, nullable=True)
    response_time_ms = Column(Float, nullable=True)
    created_at       = Column(DateTime, default=datetime.utcnow)


class ConversationMemory(Base):
    """Summarized memory of past conversations for long-term context."""
    __tablename__ = "conversation_memory"

    id              = Column(Integer, primary_key=True, index=True)
    user_id         = Column(Integer, nullable=False, index=True)
    course_id       = Column(Integer, nullable=False, index=True)
    session_key     = Column(String(100), nullable=True, index=True)
    summary         = Column(Text, nullable=True)
    key_topics      = Column(Text, nullable=True)
    active_goals    = Column(Text, nullable=True)
    message_count   = Column(Integer, default=0)
    token_count     = Column(Integer, default=0)
    created_at      = Column(DateTime, default=datetime.utcnow)
    updated_at      = Column(DateTime, default=datetime.utcnow, onupdate=datetime.utcnow)


class StudentContext(Base):
    """Student struggle profile synced from Moodle event observers."""
    __tablename__ = "student_context"

    id               = Column(Integer, primary_key=True, index=True)
    user_id          = Column(Integer, nullable=False, index=True)
    course_id        = Column(Integer, nullable=False, index=True)
    current_grade    = Column(Float, nullable=True)
    struggle_topics  = Column(Text, nullable=True)   # JSON array
    recent_events    = Column(Text, nullable=True)   # JSON array
    learning_style   = Column(String(50), default="standard")
    last_event_type  = Column(String(50), nullable=True)
    created_at       = Column(DateTime, default=datetime.utcnow)
    updated_at       = Column(DateTime, default=datetime.utcnow, onupdate=datetime.utcnow)


class MaterialAnalysis(Base):
    __tablename__ = "material_analyses"

    id              = Column(Integer, primary_key=True, index=True)
    material_id     = Column(Integer, nullable=False, index=True)
    file_id         = Column(Integer, nullable=False)
    course_id       = Column(Integer, nullable=False, index=True)
    analysis_type   = Column(String(50), nullable=False)   # full_analysis|summary|key_concepts|quiz|custom
    scope           = Column(String(50), default="full")    # full|partial:pages=2-5|partial:sections=...
    content         = Column(Text, nullable=False)          # JSON-structured analysis output
    model_version   = Column(String(100), nullable=True)
    token_count     = Column(Integer, nullable=True)
    user_request    = Column(Text, nullable=True)           # Original user prompt (for custom analyses)
    created_at      = Column(DateTime, default=datetime.utcnow)
    updated_at      = Column(DateTime, default=datetime.utcnow, onupdate=datetime.utcnow)


def init_db():
    Base.metadata.create_all(bind=engine)

    # Add session_key column to chat_logs if it doesn't exist (gradual migration)
    from sqlalchemy import inspect, text
    inspector = inspect(engine)
    columns = [c['name'] for c in inspector.get_columns('chat_logs')]
    if 'session_key' not in columns:
        with engine.connect() as conn:
            conn.execute(text("ALTER TABLE chat_logs ADD COLUMN session_key VARCHAR(100)"))
            conn.execute(text("CREATE INDEX ix_chat_logs_session_key ON chat_logs (session_key)"))
            conn.commit()

    # Create conversation_memory table if not exists
    if not inspector.has_table('conversation_memory'):
        ConversationMemory.__table__.create(bind=engine)

    # Create student_context table if not exists
    if not inspector.has_table('student_context'):
        StudentContext.__table__.create(bind=engine)


def get_db():
    db = SessionLocal()
    try:
        yield db
    finally:
        db.close()