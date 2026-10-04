from livekit import rtc

from medicon_transcriber.session import Speaker, recording_allowed, speaker_of

STANDARD = rtc.ParticipantKind.PARTICIPANT_KIND_STANDARD
AGENT = rtc.ParticipantKind.PARTICIPANT_KIND_AGENT


def doctor(consent=True):
    return Speaker("user-1", "doctor", consent)


def patient(consent=True):
    return Speaker("user-3", "patient", consent)


def test_records_only_when_both_present_and_both_consent():
    assert recording_allowed([doctor(), patient()])


def test_either_side_declining_stops_recording():
    assert not recording_allowed([doctor(False), patient()])
    assert not recording_allowed([doctor(), patient(False)])
    assert not recording_allowed([doctor(False), patient(False)])


def test_one_side_alone_is_never_recorded():
    assert not recording_allowed([doctor()])
    assert not recording_allowed([patient()])
    assert not recording_allowed([])


def test_speaker_of_reads_server_set_attributes():
    s = speaker_of(STANDARD, "user-1", {"role": "doctor", "consent": "true"})
    assert s == Speaker("user-1", "doctor", True)


def test_missing_or_odd_consent_value_means_no():
    assert speaker_of(STANDARD, "user-1", {"role": "doctor"}).consent is False
    assert speaker_of(STANDARD, "user-1", {"role": "doctor", "consent": "TRUE"}).consent is False
    assert speaker_of(STANDARD, "user-1", {"role": "doctor", "consent": "1"}).consent is False


def test_agents_and_unknown_roles_are_not_speakers():
    assert speaker_of(AGENT, "medicon-transcriber", {"role": "doctor", "consent": "true"}) is None
    assert speaker_of(STANDARD, "user-9", {"role": "admin", "consent": "true"}) is None
    assert speaker_of(STANDARD, "user-9", {}) is None
