# Local MLBB results OCR

This worker runs on the same computer as Laravel. Uploaded screenshots are stored only while OCR runs and are then deleted.

Install it once on Windows:

```powershell
py -3.13 -m pip install -r .\mlbb-ocr\requirements.txt
```

If importing PaddleOCR fails with `OSError: [WinError 10106]` in Python's `_overlapped` module, first test the Windows socket layer:

```powershell
py -3.13 -c "import _overlapped; print('Windows asyncio OK')"
```

If that produces the same error, open PowerShell as Administrator, run `netsh winsock reset`, restart Windows, and test the import again. This is a Windows networking stack issue; reinstalling PaddleOCR alone will not fix it.

PaddleOCR reads text and numeric results. Hero and item suggestions are made by matching portrait crops against local reference images. Add clean PNG or JPG assets with descriptive filenames:

The worker disables oneDNN for CPU inference to avoid a known PaddlePaddle 3.3.x PIR conversion failure.

```text
mlbb-ocr/portraits/heroes/akai.png
mlbb-ocr/portraits/heroes/paquito.png
mlbb-ocr/portraits/items/hunter-strike.png
```

Portrait matches are suggestions only. The organizer reviews and edits all hero names and item builds before saving stats.
