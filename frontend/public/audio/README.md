# Audio Gaulois

Ce dossier doit contenir la bande-son `theme.mp3` utilisée par la page Gaulois :

- Format : MP3, stéréo ou mono, 44.1 kHz.
- Licence : libre (CC0 / CC-BY / CC-BY-SA / équivalent). Documenter la source dans `/public/licenses/theme-music.txt`.

### Générer une piste simple (option CC0)
```bash
python - <<'PY'
import math
import wave

FILENAME = "theme.wav"
DURATION = 2.5  # secondes
SAMPLE_RATE = 44100
FREQUENCY = 220  # Hz
AMPLITUDE = 0.2

with wave.open(FILENAME, "w") as wav:
    wav.setnchannels(1)
    wav.setsampwidth(2)
    wav.setframerate(SAMPLE_RATE)
    for i in range(int(DURATION * SAMPLE_RATE)):
        value = int(AMPLITUDE * math.sin(2 * math.pi * FREQUENCY * (i / SAMPLE_RATE)) * 32767)
        wav.writeframes(value.to_bytes(2, "little", signed=True))
PY

# Convertir en MP3 (exige ffmpeg ou lame)
ffmpeg -y -i theme.wav theme.mp3
rm theme.wav
```

Modifier la fréquence/durée à volonté. La piste (création interne) peut être publiée sous CC0.

> ⚠️ Tant que `theme.mp3` n’est pas présent, l’autoplay n’aura aucun son.

