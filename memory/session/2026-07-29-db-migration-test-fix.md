## Changes in this turn
Added missing DB columns (title, transcription_provider, transcription_model, etc.) to `umat_ai_db.processing_jobs` table to match the updated model.

Fixed `test_error_handling_graceful_llm_failure`:
- Mock `HybridRetriever.search` instead of `VectorStoreManager.similarity_search` (correct code path)
- Mock `LLMProcessor.answer_with_prompt` instead of `LLMProcessor.answer_question` (method renamed)
- Use explicit question that avoids greeting/chitchat classification
- Result: **11/11 tests pass**
