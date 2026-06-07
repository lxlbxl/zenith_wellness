
# Create Release Package Script
$releaseDir = "release"
if (Test-Path $releaseDir) {
    Remove-Item -Recurse -Force $releaseDir
}
New-Item -ItemType Directory -Force -Path $releaseDir | Out-Null
New-Item -ItemType Directory -Force -Path "$releaseDir/public_html" | Out-Null
New-Item -ItemType Directory -Force -Path "$releaseDir/api" | Out-Null

# Copy Frontend
Write-Host "Copying Frontend..."
Copy-Item -Recurse -Force "dist/*" "$releaseDir/public_html/"

# Copy Backend
Write-Host "Copying Backend..."
# Exclude git, node_modules, tests from API if they exist in source (though api is clean usually)
# We need to copy 'api/*' contents to 'release/api/'
# But 'api' folder has everything.
Copy-Item -Recurse -Force "api/*" "$releaseDir/api/"
# Clean up unnecessary files from release/api
Remove-Item -Recurse -Force "$releaseDir/api/tests" -ErrorAction SilentlyContinue
Remove-Item -Recurse -Force "$releaseDir/api/.git" -ErrorAction SilentlyContinue
Remove-Item -Force "$releaseDir/api/.env" -ErrorAction SilentlyContinue # Don't overwrite prod env with local
Copy-Item -Force "api/.env.example" "$releaseDir/api/.env.example"

# Copy Instructions
Copy-Item -Force "DEPLOYMENT_INSTRUCTIONS.md" "$releaseDir/INSTRUCTIONS.md"

Write-Host "Release package created in ./release"
