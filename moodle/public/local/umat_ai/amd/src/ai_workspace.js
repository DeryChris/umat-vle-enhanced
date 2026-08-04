// UMaT Voice Input Recorder
// AI Chat Bar Voice-to-Text Feature
// Handles microphone recording, audio processing, transcription, and UI integration
// Implements ChatGPT-like voice input experience for editable message composition

class VoiceRecorder {
    constructor(options = {}) {
        // Core state management
        this.state = 'idle'; // idle, requesting_permission, listening, processing_audio, transcribing, ready_to_send, sending, failed, cancelled
        this.options = {
            micConstraints: {
                audio: {
                    echoCancellation: true,
                    noiseSuppression: true,
                    autoGainControl: true,
                    channelCount: 1
                }
            },
            mimeTypes: ['audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus', 'audio/mp4'],
            sampleRate: 48000,
            audioBitsPerSecond: 128000,
            videoBitsPerSecond: 0,
            msNotificationInterval: 1000,
            maxDuration: 600000, // 10 minutes
            minDuration: 500, // minimum 500ms to consider valid
            ...options
        };

        // Core components
        this.mediaRecorder = null;
        this.audioContext = null;
        this.analyser = null;
        this.microphoneStream = null;
        this.audioChunks = [];
        this.recordingStartTime = null;
        this.audioDuration = 0;
        this.waveformData = null;
        this.animationFrameId = null;
        this.timeoutId = null;
        this.permissionRequestTime = null;
        this.lastActivityTime = null;

        // UI components (initialized later via init)
        this.composer = null;
        this.micButton = null;
        this.listeningOverlay = null;
        this.waveformElement = null;
        this.cancelButton = null;
        this.confirmButton = null;
        this.transcriptInput = null;

        // Event listeners for cleanup
        this.eventListeners = [];

        // Transcription
        this.transcriptionService = new TranscriptionService();
        this.transcriptionTimeout = null;

        // Submission state
        this.isSubmitting = false;
        this.submissionQueue = [];

        // Callbacks
        this.callbacks = {
            onStateChange: null,
            onTranscription: null,
            onError: null,
            onCleanup: null,
            onReadyToSend: null,
            onMicButtonClick: null
        };

        // Cleanup flag
        this.isDestroyed = false;

        // Initialize
        this._init();
    }

    _init() {
        // Initialize state
        this._setState('idle');
    }

    async initComposerElements() {
        // Wait for DOM to be ready
        if (document.readyState === 'loading') {
            await new Promise(resolve => {
                document.addEventListener('DOMContentLoaded', resolve);
            });
        }

        // Find composer elements
        this.composer = document.querySelector('[data-umat-chat-composer]');
        this.micButton = document.querySelector('[data-umat-mic-button]');
        this.listeningOverlay = document.querySelector('[data-umat-voice-listening]');
        this.waveformElement = this.listeningOverlay?.querySelector('.voice-waveform');
        this.cancelButton = this.listeningOverlay?.querySelector('[data-umat-voice-cancel]');
        this.confirmButton = this.listeningOverlay?.querySelector('[data-umat-voice-confirm]');
        this.transcriptInput = this.listeningOverlay?.querySelector('.voice-transcript-input');

        // Validate required elements
        if (!this.listeningOverlay) {
            throw new Error('Voice listening overlay not found');
        }
        if (!this.waveformElement) {
            console.warn('Waveform element not found, using fallback');
        }

        // Setup event listeners
        this._setupEventListeners();

        // Notify initialization complete
        this._notify('initialized');
    }

    _setupEventListeners() {
        // Mic button click
        if (this.micButton) {
            this._addListener(this.micButton, 'click', this._onMicButtonClick.bind(this));
        }

        // Cancel button click
        if (this.cancelButton) {
            this._addListener(this.cancelButton, 'click', this.cancelRecording.bind(this, true));
        }

        // Confirm/Send button click
        if (this.confirmButton) {
            this._addListener(this.confirmButton, 'click', this.confirmRecording.bind(this));
        }

        // Transcript input events
        if (this.transcriptInput) {
            this._addListener(this.transcriptInput, 'input', this._onTranscriptInput.bind(this));
            this._addListener(this.transcriptInput, 'keydown', this._onTranscriptKeydown.bind(this));
        }

        // Listen for page visibility changes
        this._addListener(document, 'visibilitychange', this._onVisibilityChange.bind(this));

        // Listen for window blur
        this._addListener(window, 'blur', this._onWindowBlur.bind(this));

        // Listen for beforeunload
        this._addListener(window, 'beforeunload', this._onBeforeUnload.bind(this));
    }

    _addListener(target, type, handler) {
        if (!target || typeof handler !== 'function') return;

        // Use passive listeners where appropriate
        const options = type === 'wheel' ? { passive: true } : false;

        target.addEventListener(type, handler, options);
        this.eventListeners.push({ target, type, handler, options });
    }

    async startRecording() {
        if (this.state !== 'idle') {
            console.warn(`Cannot start recording, current state: ${this.state}`);
            return false;
        }

        // Reset state
        this.audioChunks = [];
        this.audioDuration = 0;
        this.transcription = null;

        try {
            // Request microphone permission
            this._setState('requesting_permission');
            this.microphoneStream = await this._getMicrophoneStream();

            // Detect supported MIME type
            const mimeType = this._getSupportedMimeType();
            if (!mimeType) {
                throw new Error('No supported audio format found');
            }

            // Initialize MediaRecorder with selected MIME type
            this.mediaRecorder = new MediaRecorder(this.microphoneStream, { mimeType });

            // Setup analyser for waveform visualization
            await this._setupAudioAnalyser();

            // Setup MediaRecorder event handlers
            this._setupMediaRecorderHandlers();

            // Start recording
            this.mediaRecorder.start();
            this.recordingStartTime = Date.now();
            this.lastActivityTime = Date.now();

            // Start waveform animation
            this._startWaveformAnimation();

            // Set timeout for max recording duration
            this.timeoutId = setTimeout(() => {
                if (this.state === 'listening') {
                    this._completeOrCancelRecording();
                }
            }, this.options.maxDuration);

            // Update state
            this._setState('listening');
            this._notify('listening_started');

            return true;
        } catch (error) {
            console.error('Failed to start recording:', error);
            this._cleanupRecording(true);
            this._setState('failed');
            this._notify('error', error.message);
            this._restoreComposer();
            return false;
        }
    }

    _getMicrophoneStream() {
        return navigator.mediaDevices.getUserMedia(this.options.micConstraints)
            .catch(error => {
                if (error.name === 'NotAllowedError') {
                    throw new Error('Microphone access was denied. Please allow microphone access in your browser settings and try again.');
                } else if (error.name === 'NotFoundError') {
                    throw new Error('No microphone device found. Please connect a microphone and try again.');
                } else if (error.name === 'NotSupportedError') {
                    throw new Error('Microphone recording is not supported by this browser.');
                } else {
                    throw new Error(`Microphone access failed: ${error.message}`);
                }
            });
    }

    _getSupportedMimeType() {
        return this.options.mimeTypes.find(type => MediaRecorder.isTypeSupported(type)) || null;
    }

    async _setupAudioAnalyser() {
        if (!this.microphoneStream) return;

        this.audioContext = new AudioContext();
        const source = this.audioContext.createMediaStreamSource(this.microphoneStream);
        this.analyser = this.audioContext.createAnalyser();

        // Configure analyser for better visualization
        this.analyser.fftSize = 256;
        this.analyser.smoothingTimeConstant = 0.5;
        this.analyser.minDecibels = -90;
        this.analyser.maxDecibels = -10;
        this.analyser.frequencyBinCount = 128;

        source.connect(this.analyser);

        // Create data array for waveform
        this.waveformData = new Float32Array(this.analyser.frequencyBinCount);
    }

    _setupMediaRecorderHandlers() {
        if (!this.mediaRecorder) return;

        // Data available
        this.mediaRecorder.ondataavailable = (event) => {
            if (event.data && event.data.size > 0) {
                this.audioChunks.push(event.data);
                this.lastActivityTime = Date.now();
                this.audioDuration = Date.now() - this.recordingStartTime;
            }
        };

        // Recording stopped
        this.mediaRecorder.onstop = () => {
            this.audioDuration = Date.now() - this.recordingStartTime;
            if (this.audioChunks.length > 0) {
                this._finalizeAudio();
            }
        };

        // Recording error
        this.mediaRecorder.onerror = (event) => {
            console.error('MediaRecorder error:', event.error);
            this._cleanupRecording(true);
            this._setState('failed');
            this._notify('error', `Recording error: ${event.error.message || 'Unknown error'}`);
        };
    }

    _startWaveformAnimation() {
        if (!this.waveformElement || !this.analyser) {
            // Use fallback animation
            this._startFallbackWaveformAnimation();
            return;
        }

        const updateWaveform = () => {
            if (this.state !== 'listening' || this.isDestroyed) return;

            this.analyser.getFloatFrequencyData(this.waveformData);

            // Calculate normalized waveform data
            const waveform = this.waveformData.map(value => {
                const normalized = (value + 90) / 180;
                return Math.max(0, Math.min(normalized, 1));
            });

            this._renderWaveform(waveform);
            this.animationFrameId = requestAnimationFrame(updateWaveform);
        };

        updateWaveform();
    }

    _startFallbackWaveformAnimation() {
        let phase = 0;

        const animate = () => {
            if (this.state !== 'listening' || this.isDestroyed) return;

            phase += 0.05;
            const amplitude = (Math.sin(phase) + 1) / 2 * 0.8;

            // Create a simple visual feedback based on audio activity
            this._renderWaveform([amplitude, amplitude * 0.7, amplitude * 0.4, amplitude * 0.2]);

            this.animationFrameId = setTimeout(animate, 100);
        };

        animate();
    }

    _renderWaveform(waveformData) {
        if (!this.waveformElement) return;

        const barWidth = Math.max(2, 300 / waveformData.length);
        const containerWidth = this.waveformElement.clientWidth || 300;
        const maxBars = Math.floor(containerWidth / barWidth);
        const dataToDisplay = waveformData.slice(0, maxBars);

        // Create waveform bars
        let html = '';
        dataToDisplay.forEach((amplitude, index) => {
            const height = Math.max(4, amplitude * 60);
            const hue = 120 - amplitude * 70; // Green to red gradient
            html += `<div class="waveform-bar" style="height: ${height}px; background: hsl(${hue}, 70%, 50%);"></div>`;
        });

        this.waveformElement.innerHTML = html;
    }

    _finalizeAudio() {
        if (this.audioChunks.length === 0) {
            console.warn('No audio data recorded');
            return;
        }

        this._setState('processing_audio');
        this._notify('processing_audio');

        // Check duration
        if (this.audioDuration < this.options.minDuration) {
            console.warn(`Recording too short: ${this.audioDuration}ms`);
            this._cleanupRecording(true);
            this._setState('failed');
            this._notify('error', 'No clear speech was detected. Please try again.');
            return;
        }

        // Check file size
        const totalSize = this.audioChunks.reduce((total, chunk) => total + chunk.size, 0);
        if (totalSize < 1024) { // Less than 1KB
            console.warn(`Audio file too small: ${totalSize} bytes`);
            this._cleanupRecording(true);
            this._setState('failed');
            this._notify('error', 'No clear speech was detected. Please try again.');
            return;
        }

        // Create audio blob
        const audioBlob = new Blob(this.audioChunks, {
            type: this.mediaRecorder?.mimeType || 'audio/webm'
        });

        // Process the audio (transcribe)
        this._processAudio(audioBlob);
    }

    async _processAudio(audioBlob) {
        this._setState('transcribing');
        this._notify('transcribing');

        // Clear previous timeout
        if (this.transcriptionTimeout) {
            clearTimeout(this.transcriptionTimeout);
            this.transcriptionTimeout = null;
        }

        try {
            // Upload and transcribe
            const result = await this.transcriptionService.transcribe(audioBlob);

            if (!result || !result.success) {
                throw new Error(result?.error || 'Transcription failed');
            }

            // Successfully transcribed
            this.transcription = result.transcript || '';

            // Switch to ready to send state
            this._setState('ready_to_send');
            this._showListeningOverlay(true);
            this._notify('ready_to_send', { transcript: this.transcription });

            // Set cursor at end of transcript
            if (this.transcriptInput) {
                this.transcriptInput.value = this.transcription;
                this.transcriptInput.focus();
                const length = this.transcriptInput.value.length;
                this.transcriptInput.setSelectionRange(length, length);
            }

            this._notify('transcription_complete', { transcript: this.transcription });
        } catch (error) {
            console.error('Transcription error:', error);
            this._setState('failed');
            this._notify('error', `Transcription failed: ${error.message}`);

            // Reset to allow retry
            setTimeout(() => {
                if (!this.isDestroyed) {
                    this._cleanupRecording(true);
                    this._setState('idle');
                    this._restoreComposer();
                    this._notify('error', 'Your speech could not be transcribed. Please try again.');
                }
            }, 2000);
        } finally {
            this._clearTranscriptionTimeout();
        }
    }

    _showListeningOverlay(show) {
        if (!this.listeningOverlay) return;

        if (show) {
            // Show listening interface
            this.listeningOverlay.classList.add('active');
            if (this.cancelButton) this.cancelButton.focus();

            // Hide composer interface
            if (this.composer) {
                this.composer.classList.add('hidden');
            }
        } else {
            // Hide listening interface
            this.listeningOverlay.classList.remove('active');

            // Show composer interface
            if (this.composer) {
                this.composer.classList.remove('hidden');
            }
        }
    }

    _restoreComposer() {
        this._showListeningOverlay(false);

        if (this.transcriptInput) {
            this.transcriptInput.value = '';
        }

        this.transcription = null;
    }

    confirmRecording() {
        if (this.state !== 'ready_to_send') {
            console.warn('Cannot confirm, current state:', this.state);
            return false;
        }

        if (this.isSubmitting) {
            console.warn('Already submitting message');
            return false;
        }

        // Get transcript from input or use stored transcription
        const transcript = this.transcriptInput?.value || this.transcription || '';

        if (!transcript.trim()) {
            console.warn('No transcript to send');
            return false;
        }

        // Start submitting state
        this.isSubmitting = true;
        this._setState('sending');
        this._notify('submitting', { transcript });

        // Submit the message via existing pipeline
        this._submitMessage(transcript)
            .then(() => {
                this._setState('idle');
                this.isSubmitting = false;
                this._cleanupRecording(false);
                this._restoreComposer();
                this._notify('message_sent');
            })
            .catch(error => {
                console.error('Failed to send message:', error);
                this._setState('failed');
                this.isSubmitting = false;
                this._notify('error', `Failed to send message: ${error.message}`);
            });
    }

    async _submitMessage(transcript) {
        // Use existing chat implementation
        // This should integrate with the project’s existing message sending system
        const event = new CustomEvent('umat:submit-message', {
            detail: {
                content: transcript,
                source: 'voice',
                timestamp: Date.now()
            }
        });

        const response = await new Promise((resolve, reject) => {
            const handler = (e) => {
                if (e.detail.success) {
                    resolve(e.detail);
                } else {
                    reject(new Error(e.detail.error || 'Failed to send message'));
                }
                document.removeEventListener('umat:submit-message-response', handler);
            };
            document.addEventListener('umat:submit-message-response', handler);

            // Dispatch the event to trigger message submission
            document.dispatchEvent(event);

            // Timeout for safety
            this._submissionTimeout = setTimeout(() => {
                reject(new Error('Message submission timeout'));
            }, 30000);
        });

        if (this._submissionTimeout) {
            clearTimeout(this._submissionTimeout);
        }

        return response;
    }

    cancelRecording(discardOnly = false) {
        if (this.state === 'idle' || this.state === 'failed' || this.state === 'cancelled') {
            return;
        }

        this._cleanupRecording(true);

        if (discardOnly) {
            // Discard only the current recording, keep existing composer content
            if (this.transcriptInput) {
                this.transcriptInput.value = '';
            }
            this.transcription = null;
            this._restoreComposer();
            this._setState('idle');
            this._notify('cancelled');
        } else {
            // Complete the recording without transcription (used for completion)
            this._setState('cancelled');
            this._notify('cancelled');
        }
    }

    _cleanupRecording(completely = false) {
        // Stop microphone tracks
        if (this.microphoneStream) {
            this.microphoneStream.getTracks().forEach(track => {
                track.stop();
                this.microphoneStream.removeTrack(track);
            });
            this.microphoneStream = null;
        }

        // Stop MediaRecorder
        if (this.mediaRecorder && this.mediaRecorder.state !== 'inactive') {
            this.mediaRecorder.stop();
            this.mediaRecorder = null;
        }

        // Cleanup AudioContext
        if (this.audioContext && this.audioContext.state !== 'closed') {
            try {
                this.audioContext.close();
            } catch (e) {
                console.warn('Error closing AudioContext:', e);
            }
            this.audioContext = null;
        }

        this.analyser = null;
        this.audioChunks = [];

        // Clear timeouts
        if (this.timeoutId) {
            clearTimeout(this.timeoutId);
            this.timeoutId = null;
        }

        if (this._submissionTimeout) {
            clearTimeout(this._submissionTimeout);
            this._submissionTimeout = null;
        }

        this._clearTranscriptionTimeout();

        // Cancel animation frames
        if (this.animationFrameId) {
            cancelAnimationFrame(this.animationFrameId);
            this.animationFrameId = null;
        }

        // Clear waveform data
        this.waveformData = null;

        // Notify cleanup
        this._notify('cleanup');
    }

    _clearTranscriptionTimeout() {
        if (this.transcriptionTimeout) {
            clearTimeout(this.transcriptionTimeout);
            this.transcriptionTimeout = null;
        }
    }

    _onMicButtonClick(event) {
        if (this.state === 'listening') {
            // User wants to cancel while recording
            this.cancelRecording(true);
        } else {
            // User wants to start recording
            this.startRecording();
        }
    }

    _onTranscriptInput(event) {
        this.lastActivityTime = Date.now();
        // Auto-grow textarea if needed
        const textarea = this.transcriptInput;
        if (textarea) {
            textarea.style.height = 'auto';
            textarea.style.height = `${textarea.scrollHeight}px`;
        }
    }

    _onTranscriptKeydown(event) {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            this.confirmRecording();
        } else if (event.key === 'Escape') {
            event.preventDefault();
            this.cancelRecording(true);
        }
    }

    _onVisibilityChange() {
        if (document.hidden && this.state === 'listening') {
            this._completeOrCancelRecording();
        }
    }

    _onWindowBlur() {
        if (this.state === 'listening') {
            this._completeOrCancelRecording();
        }
    }

    _onBeforeUnload() {
        this._cleanupRecording(true);
    }

    _completeOrCancelRecording() {
        if (this.state === 'listening') {
            const audioDuration = this.audioDuration;
            const hasEnoughSpeech = audioDuration >= this.options.minDuration;

            if (hasEnoughSpeech) {
                // Complete the recording
                this._showListeningOverlay(true);
                this._notify('ready_to_send', { transcript: this.transcription || '' });
            } else {
                // Not enough speech, discard
                this.cancelRecording(true);
            }
        }
    }

    _setState(newState) {
        if (this.state !== newState) {
            const oldState = this.state;
            this.state = newState;

            // Perform state-specific actions
            switch (newState) {
                case 'listening':
                    if (this.micButton) {
                        this.micButton.classList.add('active');
                        this.micButton.setAttribute('aria-label', 'Stop recording');
                        this.micButton.innerHTML = '<span class="material-symbols-outlined">stop</span>';
                    }
                    break;
                case 'idle':
                case 'failed':
                case 'cancelled':
                    if (this.micButton) {
                        this.micButton.classList.remove('active');
                        this.micButton.setAttribute('aria-label', 'Start voice input');
                        this.micButton.innerHTML = '<span class="material-symbols-outlined">mic</span>';
                    }
                    break;
            }

            this._notify('state_change', {
                oldState: oldState,
                newState: newState
            });
        }
    }

    _notify(event, data = null) {
        if (typeof this.callbacks[event] === 'function') {
            try {
                this.callbacks[event](data);
            } catch (error) {
                console.error(`Callback error for event '${event}':`, error);
            }
        }
    }

    on(event, callback) {
        if (typeof callback === 'function') {
            this.callbacks[event] = callback;
        }
        return this;
    }

    off(event) {
        this.callbacks[event] = null;
        return this;
    }

    destroy() {
        if (this.isDestroyed) return;

        this.isDestroyed = true;

        // Cleanup everything
        this._cleanupRecording(true);
        this._restoreComposer();

        // Remove all event listeners
        this.eventListeners.forEach(({ target, type, handler, options }) => {
            target.removeEventListener(type, handler, options);
        });
        this.eventListeners = [];

        // Clear timeouts and intervals
        if (this.timeoutId) {
            clearTimeout(this.timeoutId);
        }
        if (this.animationFrameId) {
            cancelAnimationFrame(this.animationFrameId);
        }
        if (this._submissionTimeout) {
            clearTimeout(this._submissionTimeout);
        }
        this._clearTranscriptionTimeout();

        // Clear callbacks
        this.callbacks = {
            onStateChange: null,
            onTranscription: null,
            onError: null,
            onCleanup: null,
            onReadyToSend: null,
            onMicButtonClick: null
        };

        // Clear references
        this.composer = null;
        this.micButton = null;
        this.listeningOverlay = null;
        this.waveformElement = null;
        this.cancelButton = null;
        this.confirmButton = null;
        this.transcriptInput = null;

        // Notify cleanup complete
        this._notify('cleanup_complete');
    }

    static getSupportedMimeTypes() {
        return ['audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus', 'audio/mp4']
            .filter(type => MediaRecorder.isTypeSupported(type));
    }

    isListening() {
        return this.state === 'listening';
    }

    isTranscribing() {
        return this.state === 'transcribing' || this.state === 'processing_audio';
    }

    isReadyToSend() {
        return this.state === 'ready_to_send';
    }

    getCurrentTranscription() {
        return this.transcription || (this.transcriptInput ? this.transcriptInput.value : '');
    }

    isDestroyed() {
        return this.isDestroyed;
    }
}

class TranscriptionService {
    constructor() {
        this.apiEndpoint = '/api/transcribe'; // The project's existing transcription endpoint
        this.timeout = 30000; // 30 seconds timeout
    }

    async transcribe(audioBlob) {
        // Validate input
        if (!audioBlob || audioBlob.size < 1024) {
            throw new Error('Audio file is too small or invalid');
        }

        const formData = new FormData();
        formData.append('audio', audioBlob, `recording_${Date.now()}.webm`);

        try {
            // Use the project's existing transcription endpoint
            const response = await fetch(this.apiEndpoint, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-MoodleFormKey': Moodle.formkey || ''
                },
                body: formData,
                timeout: this.timeout
            });

            if (!response.ok) {
                let errorMessage = 'Transcription failed';
                try {
                    const errorData = await response.json();
                    errorMessage = errorData.error || errorMessage;
                } catch (e) {
                    if (response.status === 413) {
                        errorMessage = 'Audio file too large';
                    } else if (response.status === 400) {
                        errorMessage = 'Invalid audio format';
                    } else {
                        errorMessage = `Server error: ${response.status}`;
                    }
                }
                throw new Error(errorMessage);
            }

            const data = await response.json();

            if (!data.success || !data.transcript) {
                throw new Error(data.error || 'No transcript returned');
            }

            return {
                success: true,
                transcript: data.transcript,
                confidence: data.confidence || 0.95,
                language: data.language || 'en'
            };
        } catch (error) {
            if (error.name === 'AbortError') {
                throw new Error('Transcription timeout');
            }
            if (error.message.includes('Failed to fetch') || error.message.includes('Network error')) {
                throw new Error('Network error. Please check your connection and try again.');
            }
            throw error;
        }
    }
}

// Initialize and export the voice recorder class
export { VoiceRecorder, TranscriptionService };
