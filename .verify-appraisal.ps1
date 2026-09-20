$ErrorActionPreference = 'Continue'
$base = 'http://localhost/hrdemo/api'
$jar  = Join-Path $PSScriptRoot '.va-cookies.txt'
Remove-Item $jar -ErrorAction SilentlyContinue

function Login {
  $body = Join-Path $PSScriptRoot '.va-login.json'
  [IO.File]::WriteAllText($body, '{"email":"admin@muwasco.org","password":"AdminPass2026"}')
  $out = curl.exe -s -o .va-login-out.json -w '%{http_code}' -c $jar -H 'Content-Type: application/json' --data-binary "@$body" "$base/auth/login"
  Remove-Item $body -ErrorAction SilentlyContinue
  return $out
}

$code = Login
"LOGIN http=$code"
if ($code -eq '429') {
  "  -> rate limited; clearing local rate-limit store and retrying once"
  Remove-Item (Join-Path $PSScriptRoot 'backend\storage\cache\rate-limits\*.json') -ErrorAction SilentlyContinue
  $code = Login
  "LOGIN retry http=$code"
}
$lj = $null; try { $lj = (Get-Content .va-login-out.json -Raw) | ConvertFrom-Json } catch {}
"AUTH: success=$($lj.success) user=$($lj.data.user.id) role=$($lj.data.user.role) msg=$($lj.message)"
if (-not $lj -or -not $lj.success) { 'ABORT: no session'; exit 1 }

function Get-Json($url) {
  $c = curl.exe -s -o .va-out.json -w '%{http_code}' -b $jar "$base$url"
  $raw = Get-Content .va-out.json -Raw
  $j = $null; try { $j = $raw | ConvertFrom-Json } catch { $j = $null }
  return [pscustomobject]@{ code = $c; json = $j; raw = $raw }
}

$todo = @()

# --- 1. cycles (the seeding fix) ---
$r = Get-Json '/appraisal-cycles'
$list = @($r.json.data)
"`n[CYCLES] http=$($r.code) count=$($list.Count)"
$list | ForEach-Object { "   id=$($_.id) $($_.name) $($_.start_date)..$($_.end_date) status=$($_.status) fy=$($_.financial_year_id)" }
$active = $list | Where-Object { $_.status -eq 'active' } | Select-Object -First 1
if (-not $active) { $active = $list | Select-Object -First 1 }
"CYCLE CHOSEN: id=$($active.id) name=$($active.name)"

# --- 2. list ---
$r = Get-Json '/appraisals'
$rows = @($r.json.data)
"`n[LIST] http=$($r.code) count=$($rows.Count)"
if ($rows.Count -gt 0) { "   sample: id=$($rows[0].id) emp=$($rows[0].employee_name) cyc=$($rows[0].cycle_name) score=$($rows[0].overall_score) status=$($rows[0].status) emp_id=$($rows[0].employee_id)" }
$empId = if ($rows.Count -gt 0) { $rows[0].employee_id } else { 0 }
"EMPLOYEE ID for flow test: $empId"

# --- 3. pending (ROUTE-ORDER FIX: must NOT 404 as id='pending') ---
$r = Get-Json '/appraisals/pending'
$p = @($r.json.data)
"`n[PENDING] http=$($r.code) count=$($p.Count)  <- 404 here means the wildcard still captures 'pending'"
"   statuses: $(($p | ForEach-Object { $_.status }) -join ', ')"

# --- 4. show one ---
if ($rows.Count -gt 0) {
  $id = $rows[0].id
  $r = Get-Json "/appraisals/$id"
  "`n[SHOW $id] http=$($r.code) emp=$($r.json.data.employee_name) score=$($r.json.data.overall_score) scores=$(@($r.json.data.scores).Count)"
}

# --- 5. byEmployee ---
if ($empId -gt 0) {
  $r = Get-Json "/appraisals/employee/$empId"
  "`n[BY-EMPLOYEE $empId] http=$($r.code) count=$(@($r.json.data).Count)"
}

# --- 6. create -> submit -> approve -> delete (full real workflow) ---
$createBody = Join-Path $PSScriptRoot '.va-create.json'
[IO.File]::WriteAllText($createBody, "{`"employee_id`":$empId,`"appraisal_cycle_id`":$($active.id),`"appraiser_id`":null}")
$c = curl.exe -s -o .va-out.json -w '%{http_code}' -b $jar -H 'Content-Type: application/json' --data-binary "@$createBody" "$base/appraisals"
Remove-Item $createBody -ErrorAction SilentlyContinue
$cj = $null; try { $cj = (Get-Content .va-out.json -Raw) | ConvertFrom-Json } catch {}
$newId = $cj.data.id
"`n[CREATE] http=$c success=$($cj.success) new_id=$newId msg=$($cj.message)"

if ($newId) {
  $c = curl.exe -s -o .va-out.json -w '%{http_code}' -b $jar -X PUT "$base/appraisals/$newId/submit"
  $sj = $null; try { $sj = (Get-Content .va-out.json -Raw) | ConvertFrom-Json } catch {}
  "[SUBMIT $newId] http=$c status=$($sj.data.status) msg=$($sj.message)"

  $c = curl.exe -s -o .va-out.json -w '%{http_code}' -b $jar -X PUT "$base/appraisals/$newId/approve"
  $aj = $null; try { $aj = (Get-Content .va-out.json -Raw) | ConvertFrom-Json } catch {}
  "[APPROVE $newId] http=$c status=$($aj.data.status) msg=$($aj.message)"

  # verify persistence independently via the list endpoint
  $r = Get-Json "/appraisals/$newId"
  $persisted = $r.json.data.status
  "[PERSISTED] GET /appraisals/$newId -> status=$persisted  (fail if still 'draft')"

  $c = curl.exe -s -o .va-out.json -w '%{http_code}' -b $jar -X DELETE "$base/appraisals/$newId"
  $dj = $null; try { $dj = (Get-Content .va-out.json -Raw) | ConvertFrom-Json } catch {}
  "[DELETE $newId] http=$c success=$($dj.success) msg=$($dj.message)"
  $r = Get-Json "/appraisals/$newId"
  "[AFTER DELETE] GET /appraisals/$newId -> http=$($r.code) (404 expected)"
}

Remove-Item .va-out.json, .va-login-out.json, $jar -ErrorAction SilentlyContinue
