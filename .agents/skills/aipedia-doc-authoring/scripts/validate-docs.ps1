#!/usr/bin/env pwsh

param(
    [Parameter(Position = 0, ValueFromRemainingArguments = $true)]
    [string[]] $Path
)

$ErrorActionPreference = 'Stop'
$ValidationErrors = [System.Collections.Generic.HashSet[string]]::new([System.StringComparer]::Ordinal)
$Files = [System.Collections.Generic.HashSet[string]]::new([System.StringComparer]::OrdinalIgnoreCase)
$GenericKeywords = [System.Collections.Generic.HashSet[string]]::new([System.StringComparer]::OrdinalIgnoreCase)
@('guide', 'documentation', 'software', 'best practice', 'docs') | ForEach-Object { [void] $GenericKeywords.Add($_) }

function Add-ValidationError([string] $Message) {
    [void] $script:ValidationErrors.Add($Message)
}

function Add-InputPath([string] $InputPath) {
    if (-not (Test-Path -LiteralPath $InputPath)) {
        Add-ValidationError "${InputPath}: path does not exist"
        return
    }

    $item = Get-Item -LiteralPath $InputPath
    if ($item.PSIsContainer) {
        Get-ChildItem -LiteralPath $item.FullName -Recurse -File -Filter '*.md' |
            Sort-Object FullName |
            ForEach-Object { [void] $script:Files.Add($_.FullName) }
    }
    elseif ($item.Extension -eq '.md') {
        [void] $script:Files.Add($item.FullName)
    }
}

function Test-List {
    param(
        [string] $File,
        [string] $Field,
        [string[]] $Values,
        [int] $Minimum,
        [int] $Maximum
    )

    if ($Values.Count -lt $Minimum -or $Values.Count -gt $Maximum) {
        Add-ValidationError "${File}: ${Field} must contain ${Minimum}–${Maximum} entries"
    }

    $seen = [System.Collections.Generic.HashSet[string]]::new([System.StringComparer]::OrdinalIgnoreCase)
    foreach ($value in $Values) {
        if (-not $seen.Add($value.Trim())) {
            Add-ValidationError "${File}: ${Field} contains duplicates"
        }
        if ([string]::IsNullOrWhiteSpace($value) -or $value.Contains('[')) {
            $shown = if ($value) { $value } else { '(empty)' }
            Add-ValidationError "${File}: invalid ${Field} entry: ${shown}"
        }
        elseif ($Field -eq 'tags' -and $value -cnotmatch '^[a-z0-9]+(?:-[a-z0-9]+)*$') {
            Add-ValidationError "${File}: invalid tags entry: ${value}"
        }
        elseif ($Field -eq 'keywords' -and $value -cne $value.ToLowerInvariant()) {
            Add-ValidationError "${File}: invalid keywords entry: ${value}"
        }
    }
}

function Read-Document([string] $File) {
    $bytes = [System.IO.File]::ReadAllBytes($File)
    if ($bytes.Length -ge 3 -and $bytes[0] -eq 0xEF -and $bytes[1] -eq 0xBB -and $bytes[2] -eq 0xBF) {
        Add-ValidationError "${File}: YAML frontmatter must start at byte zero"
        return $null
    }

    $text = [System.IO.File]::ReadAllText($File).Replace("`r`n", "`n").Replace("`r", "`n")
    $lines = [System.Text.RegularExpressions.Regex]::Split($text, "`n")
    if ($lines.Count -eq 0 -or $lines[0] -cne '---') {
        Add-ValidationError "${File}: YAML frontmatter must start at byte zero"
        return $null
    }

    $metadata = @{}
    $currentList = $null
    $closingIndex = -1
    for ($index = 1; $index -lt $lines.Count; $index++) {
        $line = $lines[$index]
        if ($line -ceq '---') {
            $closingIndex = $index
            break
        }
        if ($line -cmatch '^([a-z][a-z0-9_-]*):\s*(.*)$') {
            $key = $Matches[1]
            $value = $Matches[2]
            if ($key -notin @('description', 'tags', 'keywords')) {
                Add-ValidationError "${File}:$($index + 1): unsupported metadata field ${key}"
                $currentList = $null
                continue
            }
            if ($key -in @('tags', 'keywords')) {
                if (-not [string]::IsNullOrWhiteSpace($value)) {
                    Add-ValidationError "${File}:$($index + 1): ${key} must use a YAML list"
                }
                $metadata[$key] = [System.Collections.Generic.List[string]]::new()
                $currentList = $key
            }
            else {
                if ($value.Length -lt 2 -or -not $value.StartsWith('"') -or -not $value.EndsWith('"')) {
                    Add-ValidationError "${File}: description must be a double-quoted string"
                    $metadata[$key] = $value
                }
                else {
                    $metadata[$key] = $value.Substring(1, $value.Length - 2)
                }
                $currentList = $null
            }
            continue
        }
        if ($currentList -and $line -cmatch '^\s{2}-\s+(.+)$') {
            $value = $Matches[1].Trim()
            if ($value.Length -ge 2 -and (($value.StartsWith('"') -and $value.EndsWith('"')) -or ($value.StartsWith("'") -and $value.EndsWith("'")))) {
                $value = $value.Substring(1, $value.Length - 2)
            }
            $metadata[$currentList].Add($value)
            continue
        }
        if (-not [string]::IsNullOrWhiteSpace($line)) {
            Add-ValidationError "${File}:$($index + 1): unsupported frontmatter syntax"
        }
    }

    if ($closingIndex -lt 0) {
        Add-ValidationError "${File}: missing closing frontmatter delimiter"
        return $null
    }
    foreach ($field in @('description', 'tags', 'keywords')) {
        if (-not $metadata.ContainsKey($field)) {
            Add-ValidationError "${File}: missing metadata field ${field}"
        }
    }

    $description = if ($metadata.ContainsKey('description')) { [string] $metadata.description } else { '' }
    $tags = if ($metadata.ContainsKey('tags')) { [string[]] $metadata.tags } else { @() }
    $keywords = if ($metadata.ContainsKey('keywords')) { [string[]] $metadata.keywords } else { @() }
    $h1 = ''
    for ($index = $closingIndex + 1; $index -lt $lines.Count; $index++) {
        if ($lines[$index] -cmatch '^#\s+(.+)$') {
            $h1 = $Matches[1].Trim()
            break
        }
    }

    if ([string]::IsNullOrWhiteSpace($h1) -or $h1.Contains('[')) {
        Add-ValidationError "${File}: first H1 is empty or still contains a template placeholder"
    }
    if ([System.IO.Path]::GetFileName($File) -cnotmatch '^[a-z0-9]+(?:-[a-z0-9]+)*_(?:en|id)\.md$') {
        Add-ValidationError "${File}: filename must use lowercase kebab-case and end in _en.md or _id.md"
    }
    if ($description.Length -lt 40 -or $description.Length -gt 300 -or $description.Contains('[')) {
        Add-ValidationError "${File}: description must be 40–300 characters without placeholders"
    }

    Test-List -File $File -Field 'tags' -Values $tags -Minimum 2 -Maximum 6
    Test-List -File $File -Field 'keywords' -Values $keywords -Minimum 4 -Maximum 15
    foreach ($keyword in $keywords) {
        if ($GenericKeywords.Contains($keyword)) {
            Add-ValidationError "${File}: keyword is too generic: ${keyword}"
        }
    }

    [pscustomobject]@{
        File = $File
        Tags = $tags
    }
}

if (-not $Path -or $Path.Count -eq 0) {
    [Console]::Error.WriteLine('Usage: validate-docs.ps1 <file-or-directory> [...]')
    exit 2
}

foreach ($inputPath in $Path) { Add-InputPath $inputPath }

$documents = @{}
foreach ($file in $Files) {
    $document = Read-Document $file
    if ($null -ne $document) { $documents[$file] = $document }
}

foreach ($file in @($Files)) {
    if ($file -cmatch '_en\.md$') {
        $counterpart = $file -creplace '_en\.md$', '_id.md'
    }
    elseif ($file -cmatch '_id\.md$') {
        $counterpart = $file -creplace '_id\.md$', '_en.md'
    }
    else {
        Add-ValidationError "${File}: topic filename must end in _en.md or _id.md"
        continue
    }
    if (-not (Test-Path -LiteralPath $counterpart -PathType Leaf)) {
        Add-ValidationError "${file}: missing bilingual counterpart ${counterpart}"
        continue
    }
    if (-not $documents.ContainsKey($counterpart)) {
        $other = Read-Document $counterpart
        if ($null -ne $other) { $documents[$counterpart] = $other }
    }
    if ($documents.ContainsKey($file) -and $documents.ContainsKey($counterpart)) {
        $left = ($documents[$file].Tags | Sort-Object) -join ','
        $right = ($documents[$counterpart].Tags | Sort-Object) -join ','
        if ($left -cne $right) {
            Add-ValidationError "${file}: tags must match bilingual counterpart"
        }
    }
}

if ($ValidationErrors.Count -gt 0) {
    foreach ($message in $ValidationErrors) { [Console]::Error.WriteLine("ERROR ${message}") }
    exit 1
}

[Console]::Out.WriteLine("Validated $($documents.Count) documentation file(s).")
