# Chrome Extension Icons

Place PNG icon files here in these sizes:
- icon16.png (16x16)
- icon32.png (32x32)
- icon48.png (48x48)
- icon128.png (128x128)

You can generate them from any logo/icon. For development, you can use any placeholder icons.

## Quick generation (requires ImageMagick):
```bash
for size in 16 32 48 128; do
  convert -size ${size}x${size} xc:#4F46E5 -fill white -gravity center \
    -pointsize $((size/2)) -annotate 0 "P" icon${size}.png
done
```
