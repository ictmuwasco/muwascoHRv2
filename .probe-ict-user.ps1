$ErrorActionPreference = 'Continue'
$body = '{"email":"duncan@muwasco.org","password":"Duncan2026"}'
[IO.File]::WriteAllText('.vloginD.json', $body)

$null = curl.exe -s -c .vjarD.txt -H 'Content-Type: application/json' `
  --data-binary '@.vloginD.json' 'http://localhost/hrdemo/api/auth/login' `
  -o .voutD.json

$dlogin = Get-Content .voutD.json -Raw
Write-Output "LOGIN raw: $dlogin"
Remove-Item .vloginD.json -ErrorAction SilentlyContinue

if ($dlogin -match '"success":true') {
  $null = curl.exe -s -b .vjarD.txt 'http://localhost/hrdemo/api/auth/user' -o .vuserD.json
  $user = Get-Content .vuserD.json -Raw
  Write-Output "USER raw: $user"
  Remove-Item .vjarD.txt, .vuserD.json -ErrorAction SilentlyContinue
} else {
  Write-Output 'LOGIN FAILED — skipping user fetch'
  Remove-Item .vjarD.txt, .voutD.json -ErrorAction SilentlyContinue
}
