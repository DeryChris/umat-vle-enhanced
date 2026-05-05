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
    question         = Column(Text, nullable=False)
    answer           = Column(Text, nullable=True)
    sources          = Column(Text, nullable=True)
    response_time_ms = Column(Float, nullable=True)
    created_at       = Column(DateTime, default=datetime.utcnow)


def init_db():
    Base.metadata.create_all(bind=engine)


def get_db():
    db = SessionLocal()
    try:
        yield db
    finally:
        db.close()