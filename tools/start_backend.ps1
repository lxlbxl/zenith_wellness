
# Start PHP Server for Zenith Wellness API
Write-Host "Starting Zenith Wellness Backend on http://localhost:8000..."
Write-Host "Press Ctrl+C to stop."

# Run PHP built-in server with the 'api' directory as the document root, using the new router.
php -S localhost:8000 -t api api/router.php
