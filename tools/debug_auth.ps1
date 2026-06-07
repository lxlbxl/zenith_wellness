
# Debug Admin Auth
$headers = @{ "Content-Type" = "application/json" }
$body = @{ email = "test@zenith.com"; password = "password" } | ConvertTo-Json

Write-Host "1. Logging in..."
try {
    $response = Invoke-RestMethod -Uri "http://localhost:8000/api/auth/login" -Method Post -Body $body -Headers $headers
    $token = $response.token
    Write-Host "Token received: $($token.Substring(0, 20))..."
    Write-Host "User Role: $($response.user.role)"
}
catch {
    Write-Host "Login Failed: $_"
    exit
}

Write-Host "`n2. Testing Admin Stats..."
$adminHeaders = @{ "Authorization" = "Bearer $token"; "Content-Type" = "application/json" }
try {
    $stats = Invoke-RestMethod -Uri "http://localhost:8000/api/admin/stats" -Method Get -Headers $adminHeaders
    Write-Host "Stats Success!"
    Write-Host ($stats | ConvertTo-Json -Depth 2)
}
catch {
    Write-Error "Admin Stats Failed: $_"
    # Print detailed error response if available
    if ($_.Exception.Response) {
        $reader = New-Object System.IO.StreamReader($_.Exception.Response.GetResponseStream())
        Write-Host "Response Body: $($reader.ReadToEnd())"
    }
}
