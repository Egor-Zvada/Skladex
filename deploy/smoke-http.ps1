#requires -Version 7.0
param(
    [uri]$BaseUri = 'https://skladex.vve.local',
    [PSCredential]$Credential
)
$ErrorActionPreference = 'Stop'
if ($BaseUri.Scheme -ne 'https') { throw 'Use HTTPS with normal certificate validation.' }
$base = $BaseUri.AbsoluteUri.TrimEnd('/')
$results = [System.Collections.Generic.List[object]]::new()
function Check-Page([string]$Path, [int]$Expected, $Session = $null) {
    $response = Invoke-WebRequest -Uri ($base + $Path) -WebSession $Session -SkipHttpErrorCheck
    if ([int]$response.StatusCode -ne $Expected) { throw "$Path returned $($response.StatusCode), expected $Expected" }
    $results.Add([pscustomobject]@{path=$Path;status=[int]$response.StatusCode;final_path=$response.BaseResponse.RequestMessage.RequestUri.AbsolutePath})
    return $response
}
$null = Check-Page '/' 200
foreach ($path in @('/assets','/assets/','/assets/create')) {
    $page = Check-Page $path 200
    if ($page.BaseResponse.RequestMessage.RequestUri.AbsolutePath -ne '/login') { throw "$path did not require login" }
}
foreach ($path in @('/static/skladex.css','/static/skladex.js','/static/operations.js','/static/qr-scanner.min.js','/static/link.png','/static/fonts/InterVariable.woff2','/static/label-editor.js','/static/label-editor.css')) { $null = Check-Page $path 200 }
foreach ($path in @('/assets/skladex.css','/assets/qr-scanner.min.js','/assets/fonts/InterVariable.woff2','/assets/fonts/IBMPlexMono-Light.woff2','/assets/fonts/IBMPlexMono-Regular.woff2')) {
    $page = Check-Page $path 200
    if ($page.BaseResponse.RequestMessage.RequestUri.AbsolutePath -ne $path.Replace('/assets/','/static/')) { throw 'Legacy static compatibility failed.' }
}
$probe = Invoke-WebRequest -Uri ($base + '/assets/fonts/a%0d%0aX-Skladex-Probe:%20yes.woff2') -SkipHttpErrorCheck
if ([int]$probe.StatusCode -notin @(400,404) -or $probe.Headers.ContainsKey('X-Skladex-Probe')) { throw 'Unexpected redirect/header for invalid static path.' }
$null = Check-Page '/static/missing-file.css' 404
$null = Check-Page '/api/v1/assets' 401
if ($Credential) {
    $login = Invoke-WebRequest -Uri ($base + '/login') -SessionVariable session
    $csrf = [regex]::Match($login.Content, 'name="_token" value="([^"]+)"').Groups[1].Value
    if (-not $csrf) { throw 'Login form has no CSRF token.' }
    $dashboard = Invoke-WebRequest -Uri ($base + '/login') -Method Post -WebSession $session -Body @{_token=$csrf;email=$Credential.UserName;password=$Credential.GetNetworkCredential().Password}
    if ($dashboard.BaseResponse.RequestMessage.RequestUri.AbsolutePath -ne '/app') { throw 'Authentication failed.' }
    try {
        foreach ($path in @('/app','/assets','/assets/','/assets/create','/locations','/locations?building_id=unassigned','/buildings','/assets?building_id=unassigned','/kits','/inventory','/maintenance','/software','/licenses','/labels','/history','/history?source=legacy','/settings','/api-reference','/api-reference/openapi.json')) {
            $page = Check-Page $path 200 $session
            if ($page.BaseResponse.RequestMessage.RequestUri.AbsolutePath -eq '/login') { throw "$path lost authentication" }
        }
        $html = (Check-Page '/assets' 200 $session).Content
        $existingAsset = [regex]::Match($html, 'href="(?:https?://[^/"\s]+)?(/assets/[a-f0-9-]{36})"')
        if ($existingAsset.Success) {
            $null = Check-Page $existingAsset.Groups[1].Value 200 $session
            $null = Check-Page ($existingAsset.Groups[1].Value + '/edit') 200 $session
        }
        foreach ($match in [regex]::Matches($html, '(?:href|src)="([^"]*/static/[^"?#]+)(?:\?[^"#]*)?"')) {
            $url = [uri]::new($BaseUri, $match.Groups[1].Value)
            if ($url.Authority -ne $BaseUri.Authority) { throw 'Static dependency is not local.' }
            $null = Check-Page $url.AbsolutePath 200 $session
        }
        $labels = Check-Page '/labels' 200 $session
        if ($labels.Content -notmatch 'data-label-editor' -or $labels.Content -notmatch '/static/label-editor.js') { throw 'Live label editor missing.' }
        $editorCsrf = [regex]::Match($labels.Content, 'name="_token" value="([^"]+)"').Groups[1].Value
        $previewBody = @{_token=$editorCsrf;editor=$true;target_type='asset';target_ids=@();qr_indices=@();start_cell=1}
        foreach ($name in @('paper_width_mm','paper_height_mm','label_width_mm','label_height_mm','columns','rows','gap_x_mm','gap_y_mm','margin_left_mm','margin_top_mm','offset_x_mm','offset_y_mm')) {
            $value = [regex]::Match($labels.Content, ('name="' + $name + '"[^>]*value="([^"]+)"')).Groups[1].Value
            if ($value -eq '') { throw "Missing geometry input $name" }
            $previewBody[$name] = $value
        }
        $preview = Invoke-WebRequest -Uri ($base + '/labels/preview') -Method Post -WebSession $session -Headers @{Accept='application/json'} -ContentType 'application/json' -Body ($previewBody | ConvertTo-Json)
        if (($preview.Content | ConvertFrom-Json).data.layout.pages_count -ne 1) { throw 'Empty live sheet preview failed.' }
        $results.Add([pscustomobject]@{path='/labels/preview';status=[int]$preview.StatusCode;final_path='/labels/preview'})
    } finally {
        $logoutCsrf = [regex]::Match($dashboard.Content, 'name="_token" value="([^"]+)"').Groups[1].Value
        $null = Invoke-WebRequest -Uri ($base + '/logout') -Method Post -WebSession $session -Body @{_token=$logoutCsrf}
    }
}
$results | ConvertTo-Json -Depth 3
