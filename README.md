# Free Youtube Thumbnail Generator

A lightweight, single‑page canvas app to compose YouTube‑style thumbnails. Load or generate background/overlay images, position a pose cutout, add two big headline lines, tweak transforms (x/y/scale/rotation/mirror), and export a ready‑to‑use image.

The frontend is pure HTML5 Canvas (`index.html`). A tiny PHP helper (`image.php`) handles two things:
- Generating images from prompts via Google Gemini (by sending `gemini://<prompt>` URLs)
- Proxying HTTPS image URLs (e.g., Unsplash) to avoid CORS and attach an API key when available

## Features
- Background, SecondImage, ThirdImage inputs (URL or local file)
- Pose picker with 28 built‑in thumbnails (`img/poses` and `img/poses_thumbs`)
- Pose chroma‑key masking with color sampling, tolerance and softness controls
- Two text lines with stylized rendering and transform controls per element
- Per‑element transforms: x, y, scale, rotation, mirror horizontal/vertical
- Prompt‑based generation buttons (P → Generate) for Background/Second/Third/Pose
- Deep link of current state via URL query string
- One‑click "Download" to export the canvas as a JPEG

## Requirements
- PHP 8+ with cURL extension enabled
- Internet access for:
  - Gemini image generation (required when using prompts)
  - Unsplash (optional, if you paste Unsplash links)
- A simple local PHP server (e.g., `php -S`)

## Quick start
```bash
# From the project root
sudo php -S 127.0.0.1:80
# Then open in your browser:
# http://127.0.0.1:80/index.html
```

If you plan to use prompt‑based generation, create a `.env` file first (see below).

## Environment variables (.env)
Create a file named `.env` in the project root. The file is git‑ignored.

```dotenv
# Required for prompt-based generation via Gemini
GEMINI_API_KEY=your_gemini_api_key

# Optional: appended when proxying Unsplash links
UNSPLASH_CLIENT_ID=your_unsplash_client_id
```

`image.php` loads `.env` keys and will return errors if `GEMINI_API_KEY` is missing when using `gemini://` prompts.

## How to use
1. Open `index.html` in your browser (via the local PHP server).
2. Choose sources:
   - Background/SecondImage/ThirdImage: paste an HTTPS URL or pick a local file.
   - Click the small "P" button to reveal a prompt field, then press "Generate" to create an image via Gemini.
   - Pose: pick an index (0–27). Optionally enter a prompt to generate a new pose based on the current pose image.
3. Adjust transforms for each element (x/y/scale/rotation/mirror) using the sliders next to each input.
4. Pose masking:
   - Toggle Mask to enable chroma‑key removal.
   - Use Sample to pick a key color from the pose image border.
   - Tweak Tolerance and Softness for cleaner edges.
5. Text lines:
   - Fill `Text1` and `Text2`, then use their transform sliders to place/scale/rotate.
6. DeepLink updates as you work; copy it to share a reproducible state.
7. Click Download to save the composed canvas as a JPEG.

## Controls

### Keyboard
- **Selection**
  - **1**: Background
  - **2**: Pose
  - **3**: Text1
  - **4**: Text2
  - **5**: SecondImage
  - **6**: ThirdImage
  - **0 / 7 / 8 / 9**: Clear selection
- **Move**
  - **Arrow keys** or **W/A/S/D**: Move selected element
  - **Hold Shift**: Fine step movement (1px instead of 10px)
- **Rotate**
  - **Q / E**: Rotate selected element
  - **Hold Shift**: Fine rotation (1° instead of 5°)
- **Scale**
  - **+ / -**: Increase / decrease scale of selected element
  - **R / F**: Alternative scale shortcuts (R = bigger, F = smaller)
  - **Hold Shift**: Fine scale steps

### Mouse and touch
- **Left‑click** canvas to select; click again on the same spot to cycle layers under the cursor.
- **Drag (Left mouse)**: Move selected element.
- **Drag (Middle mouse)**: Scale selected element.
- **Drag (Right mouse)**: Rotate selected element.
- **Mouse wheel over canvas**: Scale selected element (Shift = reduce sensitivity).
- **Touch (one finger)**: Drag to move selected element.

### Lists and assets
- **AI Poses** and **AI Backgrounds** are paginated by default; use the “Show all” button to reveal all items.
- **Delete generated item**: Click the “×” on a thumbnail. Hold **Shift** while clicking to delete without confirmation.

## How it works
- Frontend (`index.html`):
  - Renders everything via HTML5 Canvas in 1280×720 by default.
  - Keeps UI state in the query string; the "DeepLink" anchor reflects the current state.
  - Uses a custom `gemini://<prompt>` URL scheme for on‑demand generation.
- Backend (`image.php`):
  - If the incoming URL starts with `gemini://`, it calls Google Gemini 2.5 Flash Image (`generateContent`) with the prompt. When generating a pose, the current pose image is attached as inline data for editing guidance, and a 16:9 aspect ratio is requested.
  - Saves generated images to `img/gemini/gemini-<sha1>.png` (disk cache) and serves them with long‑term cache headers.
  - If the incoming URL is HTTPS (e.g., Unsplash), it proxies and forwards only image content types, optionally adding `client_id` when `UNSPLASH_CLIENT_ID` is set.

## Caching
Generated images are cached on disk under `img/gemini/` using a SHA‑1 hash of the prompt and optional pose reference. The folder is ignored by git (see `.gitignore`). Subsequent requests serve cached files directly.

## Troubleshooting
- "Missing GEMINI_API_KEY": add it to `.env` before using prompt generation.
- Nothing renders from an HTTPS URL:
  - Ensure the URL points to an image and is publicly accessible.
  - `image.php` only proxies image content types (PNG/JPEG/GIF/WebP/SVG/ICO).
- Timeouts or partial downloads when proxying: the proxy limits very large files and has conservative timeouts; try a smaller image.
- 404 from proxy: the target didn’t return an image `Content-Type`.
- PHP errors: run the built‑in server in the project root so relative paths resolve.

## Folder structure (high level)
```
/               # project root
├─ index.html   # canvas UI and rendering logic
├─ image.php    # Gemini generation + HTTPS image proxy
├─ img/
│  ├─ poses/           # pose source images
│  ├─ poses_thumbs/    # pose thumbnails
│  └─ gemini/          # generated images cache (git-ignored)
└─ .env         # your secrets (git-ignored)
```

## Security and privacy
- Never commit `.env` to source control (already git‑ignored).
- Requests to third‑party services (Gemini, Unsplash) include your API keys when configured.
- The proxy only forwards image responses and strips non‑image content.

## Pose image preparation (9:16 utility)
Use `misc/pose_9_16.sh` to batch‑prepare pose assets for consistent compositing:
- Trims transparent edges.
- Pads to exact 9:16 with bottom alignment (no padding below the subject).
- Unifies all results to the same 9:16 size (largest width among inputs).
- Optionally optimizes PNGs with `pngquant` if installed.

Requirements:
- ImageMagick (`magick` or `convert`/`identify`), optional `pngquant`.

Typical usage:
- From the project root, targeting `img/poses`:
```bash
bash misc/pose_9_16.sh img/poses_9x16 img/poses/*.{png,PNG,webp,WEBP,jpg,JPG,jpeg,JPEG}
```
- Or from inside `img/poses`:
```bash
cd img/poses
bash ../../misc/pose_9_16.sh poses_9x16
```

Outputs:
- Raw 9:16 results: `<OUTDIR>` (default `_out_9x16`)
- Uniform + optimized: `<OUTDIR>_uniform`

Notes:
- Outputs are PNG with alpha preserved.
- After reviewing results in `<OUTDIR>_uniform`, you can copy them into `img/poses/`.
- Thumbnails in `img/poses_thumbs/` are not created by this script.

## License
MIT License

Copyright (c) 2025

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.


