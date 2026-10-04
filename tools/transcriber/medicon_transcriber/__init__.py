"""MediCon consultation transcriber agent (Phase 6.5).

Joins every ``appointment-*`` LiveKit room, records each speaker's microphone
to FLAC chunks only while the doctor and the patient have both consented, and
uploads the chunks to the Laravel API for transcription.
"""
