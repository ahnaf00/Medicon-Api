import sys
from pathlib import Path

# Make the medicon_transcriber package importable when running `pytest` from this folder.
sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
