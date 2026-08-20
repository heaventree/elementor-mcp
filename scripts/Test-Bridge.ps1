<#
.SYNOPSIS
    Checks that the Elementor MCP Bridge is installed, reachable and authenticating.

.DESCRIPTION
    Works through the four things that must be true, in order, so a failure tells
    you which one broke rather than just "it didn't work":

      1. the site answers at all
      2. the WordPress REST API is exposed
      3. the bridge plugin is active and its namespace is registered
      4. the application password authenticates and has the right capabilities

.PARAMETER SiteUrl
    Site root, e.g. https://example.com — no trailing /wp-json.

.PARAMETER Username
    WordPress username.

.PARAMETER AppPassword
    Application password from Users > Profile > Application Passwords.
    Spaces are fine; paste it exactly as WordPress displayed it.

.EXAMPLE
    .\Test-Bridge.ps1 -SiteUrl https://example.com -Username admin -AppPassword 'abcd efgh ijkl mnop qrst uvwx'
#>

[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)][string] $SiteUrl,
    [Parameter(Mandatory = $true)][string] $Username,
    [Parameter(Mandatory = $true)][string] $AppPassword
)

$ErrorActionPreference = 'Stop'

# Windows PowerShell 5.1 still negotiates TLS 1.0 by default, which most hosts
# now refuse outright.
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

$SiteUrl = $SiteUrl.TrimEnd('/') -replace '/wp-json$', ''

# WordPress does not reliably issue a Basic auth challenge, and PowerShell's
# -Credential only sends credentials after a challenge. Build the header itself.
$pair   = "{0}:{1}" -f $Username, $AppPassword
$token  = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($pair))
$auth   = @{ Authorization = "Basic $token" }

function Write-Step { param($n, $text) Write-Host "`n[$n] $text" -ForegroundColor Cyan }
function Write-Pass { param($text) Write-Host "    PASS  $text" -ForegroundColor Green }
function Write-Fail { param($text) Write-Host "    FAIL  $text" -ForegroundColor Red }
function Write-Note { param($text) Write-Host "          $text" -ForegroundColor DarkGray }

$failed = $false

# --- 1. Site reachable -------------------------------------------------------
Write-Step 1 "Site reachable: $SiteUrl"
try {
    $r = Invoke-WebRequest -Uri $SiteUrl -Method Head -TimeoutSec 30 -UseBasicParsing
    Write-Pass "HTTP $($r.StatusCode)"
}
catch {
    Write-Fail $_.Exception.Message
    Write-Note "Check the URL and that the site is up. Nothing below can pass until this does."
    exit 1
}

# --- 2. REST API exposed -----------------------------------------------------
Write-Step 2 "WordPress REST API"
try {
    $index = Invoke-RestMethod -Uri "$SiteUrl/wp-json/" -TimeoutSec 30
    Write-Pass "REST API responding"
}
catch {
    Write-Fail $_.Exception.Message
    Write-Note "The REST API is disabled or blocked. Some security plugins and WAFs turn it off."
    exit 1
}

# --- 3. Bridge plugin active -------------------------------------------------
Write-Step 3 "Bridge namespace registered"
if ($index.namespaces -contains 'elementor-mcp/v1') {
    Write-Pass "elementor-mcp/v1 is registered"
}
else {
    Write-Fail "elementor-mcp/v1 is NOT registered"
    Write-Note "The plugin is not active. Activate 'Elementor MCP Bridge' under Plugins."
    Write-Note "Namespaces found: $($index.namespaces -join ', ')"
    exit 1
}

# --- 4. Authentication and capabilities --------------------------------------
Write-Step 4 "Authentication and capabilities"
try {
    $status = Invoke-RestMethod -Uri "$SiteUrl/wp-json/elementor-mcp/v1/status" -Headers $auth -TimeoutSec 30
    Write-Pass "Authenticated as user id $($status.user.id)"
}
catch {
    $code = $null
    if ($_.Exception.Response) { $code = [int]$_.Exception.Response.StatusCode }

    Write-Fail "HTTP $code — $($_.Exception.Message)"

    if ($code -eq 401) {
        Write-Note "Username or application password is wrong, OR the server is stripping the"
        Write-Note "Authorization header (common on CGI/FastCGI). If the credentials are right,"
        Write-Note "add this to .htaccess above the WordPress block:"
        Write-Note '    RewriteCond %{HTTP:Authorization} ^(.*)'
        Write-Note '    RewriteRule .* - [e=HTTP_AUTHORIZATION:%1]'
    }
    elseif ($code -eq 403) {
        Write-Note "Authenticated, but this user lacks edit_posts. Use an Editor or Administrator."
    }
    exit 1
}

# --- Report ------------------------------------------------------------------
Write-Host "`n--- Environment ---" -ForegroundColor Cyan
Write-Host ("  WordPress        : {0}" -f $status.wordpress)
Write-Host ("  PHP              : {0}" -f $status.php)
Write-Host ("  Bridge           : {0}" -f $status.bridgeVersion)
Write-Host ("  Elementor active : {0}" -f $status.elementorActive)
Write-Host ("  Elementor        : {0}" -f $(if ($status.elementorVersion) { $status.elementorVersion } else { 'not installed' }))
Write-Host ("  Elementor Pro    : {0}" -f $(if ($status.elementorPro) { $status.elementorPro } else { 'not installed' }))
Write-Host ("  Widgets          : {0}" -f $status.widgetCount)
Write-Host ("  Active kit id    : {0}" -f $status.activeKitId)
Write-Host ("  Destructive ops  : {0}" -f $status.destructiveEnabled)

Write-Host "`n--- Permissions ---" -ForegroundColor Cyan
Write-Host ("  Edit posts       : {0}" -f $status.user.canEditPosts)
Write-Host ("  Manage globals   : {0}" -f $status.user.canManageGlobals)
Write-Host ("  Upload files     : {0}" -f $status.user.canUploadFiles)

if (-not $status.elementorActive) {
    Write-Host "`nWARNING: Elementor is not active. The bridge loaded, but every Elementor route will return 501." -ForegroundColor Yellow
    $failed = $true
}
if (-not $status.user.canEditPosts) {
    Write-Host "`nWARNING: This user cannot edit posts, so no editing tool will work." -ForegroundColor Yellow
    $failed = $true
}

if ($failed) {
    Write-Host "`nBRIDGE REACHABLE, BUT NOT READY — see warnings above.`n" -ForegroundColor Yellow
    exit 2
}

Write-Host "`nBRIDGE OK — ready to use.`n" -ForegroundColor Green
