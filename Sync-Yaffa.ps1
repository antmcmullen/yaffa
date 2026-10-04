[CmdletBinding()]
param(
    [switch]$Push,

    [string]$UpstreamUrl = "https://github.com/kantorge/yaffa.git",
    [string]$UpstreamRemote = "upstream",
    [string]$UpstreamBranch = "develop",
    [string]$LocalDevelop = "develop",
    [string]$OriginRemote = "origin",

    [string[]]$FeatureBranches = @(
        "add_reconcile",
        "investment-active-first"
    )
)

$ErrorActionPreference = "Stop"

function Invoke-Git {
    param(
        [Parameter(Mandatory = $true)]
        [string[]]$Arguments
    )

    Write-Host ""
    Write-Host "+ git $($Arguments -join ' ')" -ForegroundColor DarkGray

    & git @Arguments

    if ($LASTEXITCODE -ne 0) {
        throw "Git command failed with exit code $LASTEXITCODE."
    }
}

function Test-GitReference {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Reference
    )

    & git show-ref --verify --quiet "refs/heads/$Reference"
    return ($LASTEXITCODE -eq 0)
}

try {
    # Confirm this is a Git repository and move to its root.
    $RepoRoot = & git rev-parse --show-toplevel 2>$null

    if ($LASTEXITCODE -ne 0) {
        throw "This script must be run inside a Git repository."
    }

    Set-Location $RepoRoot

    Write-Host "Repository: $RepoRoot"

    # Do not merge over uncommitted work.
    $Status = @(git status --porcelain)

    if ($Status.Count -gt 0) {
        throw "Your working tree is not clean. Commit or stash your changes first."
    }

    # Add the upstream remote if it does not already exist.
    & git remote get-url $UpstreamRemote 2>$null

    if ($LASTEXITCODE -ne 0) {
        Write-Host "Adding remote '$UpstreamRemote': $UpstreamUrl"
        Invoke-Git @("remote", "add", $UpstreamRemote, $UpstreamUrl)
    }
    else {
        $CurrentUpstreamUrl = & git remote get-url $UpstreamRemote
        Write-Host "Using existing '$UpstreamRemote' remote: $CurrentUpstreamUrl"
    }

    if ($Push) {
        & git remote get-url $OriginRemote 2>$null

        if ($LASTEXITCODE -ne 0) {
            throw "Cannot use -Push because the '$OriginRemote' remote does not exist."
        }
    }

    # Fetch the latest upstream development branch.
    Write-Host ""
    Write-Host "Fetching $UpstreamRemote/$UpstreamBranch..."
    Invoke-Git @("fetch", $UpstreamRemote, $UpstreamBranch)

    $UpstreamRef = "${UpstreamRemote}/${UpstreamBranch}"

    # Create the local integration branch if it does not exist.
    if (-not (Test-GitReference $LocalDevelop)) {
        Write-Host "Creating local branch '$LocalDevelop' from $UpstreamRef..."
        Invoke-Git @("switch", "--create", $LocalDevelop, $UpstreamRef)
    }
    else {
        Invoke-Git @("switch", $LocalDevelop)
    }

    # Merge upstream changes without replacing local improvements.
    Write-Host ""
    Write-Host "Merging $UpstreamRef into $LocalDevelop..."

    try {
        Invoke-Git @(
            "merge",
            "--no-ff",
            "--no-edit",
            $UpstreamRef,
            "-m",
            "Merge $UpstreamRef into $LocalDevelop"
        )
    }
    catch {
        Write-Host ""
        Write-Host "The upstream merge has conflicts." -ForegroundColor Yellow
        Write-Host "Resolve the conflicts, then run:"
        Write-Host "  git add <resolved-files>"
        Write-Host "  git commit"
        Write-Host ""
        Write-Host "After committing the resolution, rerun this script."
        exit 1
    }

    # Merge each active improvement branch into local develop.
    foreach ($FeatureBranch in $FeatureBranches) {
        if (-not (Test-GitReference $FeatureBranch)) {
            throw "Required local branch '$FeatureBranch' does not exist."
        }

        Write-Host ""
        Write-Host "Merging $FeatureBranch into $LocalDevelop..."

        try {
            Invoke-Git @(
                "merge",
                "--no-ff",
                "--no-edit",
                $FeatureBranch,
                "-m",
                "Merge $FeatureBranch into $LocalDevelop"
            )
        }
        catch {
            Write-Host ""
            Write-Host "The merge of '$FeatureBranch' has conflicts." -ForegroundColor Yellow
            Write-Host "Resolve the conflicts, then run:"
            Write-Host "  git add <resolved-files>"
            Write-Host "  git commit"
            Write-Host ""
            Write-Host "After committing the resolution, rerun this script."
            exit 1
        }
    }

    # Optionally publish local develop to your fork.
    if ($Push) {
        Write-Host ""
        Write-Host "Pushing $LocalDevelop to $OriginRemote..."
        Invoke-Git @("push", $OriginRemote, $LocalDevelop)
    }

    Write-Host ""
    Write-Host "Sync complete." -ForegroundColor Green
    Write-Host ""
    Write-Host "Current branch:"
    git branch --show-current

    Write-Host ""
    Write-Host "Recent history:"
    git --no-pager log --oneline --decorate -8
}
catch {
    Write-Host ""
    Write-Host "ERROR: $($_.Exception.Message)" -ForegroundColor Red
    exit 1
}