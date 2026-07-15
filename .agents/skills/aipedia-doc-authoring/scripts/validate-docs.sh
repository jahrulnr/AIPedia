#!/usr/bin/env bash

set -o pipefail

declare -a ERRORS=()
declare -a FILES=()
declare -a DOC_TAGS=()
declare -a DOC_KEYWORDS=()
DOC_DESCRIPTION=''
DOC_H1=''

add_error() {
  local message=$1 existing
  for existing in "${ERRORS[@]}"; do
    [[ "$existing" == "$message" ]] && return
  done
  ERRORS+=("$message")
}

add_file() {
  local file=$1 existing
  for existing in "${FILES[@]}"; do
    [[ "$existing" == "$file" ]] && return
  done
  FILES+=("$file")
}

collect_path() {
  local input=$1 file
  if [[ ! -e "$input" ]]; then
    add_error "$input: path does not exist"
    return
  fi
  if [[ -d "$input" ]]; then
    while IFS= read -r file; do
      add_file "$file"
    done < <(find "$input" -type f -name '*.md' -print | LC_ALL=C sort)
  elif [[ "$input" == *.md ]]; then
    add_file "$(cd "$(dirname "$input")" && pwd -P)/$(basename "$input")"
  fi
}

lowercase() {
  printf '%s' "$1" | tr '[:upper:]' '[:lower:]'
}

validate_list() {
  local file=$1 field=$2 min=$3 max=$4 value normalized seen='' count
  shift 4
  count=$#
  if (( count < min || count > max )); then
    add_error "$file: $field must contain $min–$max entries"
  fi
  for value in "$@"; do
    normalized=$(lowercase "${value#${value%%[![:space:]]*}}")
    normalized=${normalized%${normalized##*[![:space:]]}}
    if [[ "
$seen
" == *"
$normalized
"* ]]; then
      add_error "$file: $field contains duplicates"
    fi
    seen+=$'\n'"$normalized"

    if [[ -z "$value" || "$value" == *'['* ]]; then
      add_error "$file: invalid $field entry: ${value:-'(empty)'}"
    elif [[ "$field" == tags && ! "$value" =~ ^[a-z0-9]+(-[a-z0-9]+)*$ ]]; then
      add_error "$file: invalid tags entry: $value"
    elif [[ "$field" == keywords && "$value" != "$(lowercase "$value")" ]]; then
      add_error "$file: invalid keywords entry: $value"
    fi
  done
}

parse_document() {
  local file=$1 line='' line_no=0 in_frontmatter=1 closed=0 current_list='' key value item
  local seen_description=0 seen_tags=0 seen_keywords=0
  DOC_DESCRIPTION=''
  DOC_H1=''
  DOC_TAGS=()
  DOC_KEYWORDS=()

  while IFS= read -r line || [[ -n "$line" ]]; do
    line=${line%$'\r'}
    ((line_no += 1))

    if (( line_no == 1 )); then
      if [[ "$line" != '---' ]]; then
        add_error "$file: YAML frontmatter must start at byte zero"
        return 1
      fi
      continue
    fi

    if (( in_frontmatter )); then
      if [[ "$line" == '---' ]]; then
        in_frontmatter=0
        closed=1
        current_list=''
        continue
      fi

      if [[ "$line" =~ ^([a-z][a-z0-9_-]*):[[:space:]]*(.*)$ ]]; then
        key=${BASH_REMATCH[1]}
        value=${BASH_REMATCH[2]}
        case "$key" in
          description)
            seen_description=1
            current_list=''
            if [[ ${#value} -lt 2 || ${value:0:1} != '"' || ${value: -1} != '"' ]]; then
              add_error "$file: description must be a double-quoted string"
              DOC_DESCRIPTION=$value
            else
              DOC_DESCRIPTION=${value:1:${#value}-2}
            fi
            ;;
          tags|keywords)
            [[ -n "$value" ]] && add_error "$file:$line_no: $key must use a YAML list"
            current_list=$key
            [[ "$key" == tags ]] && seen_tags=1 || seen_keywords=1
            ;;
          *)
            add_error "$file:$line_no: unsupported metadata field $key"
            current_list=''
            ;;
        esac
        continue
      fi

      if [[ "$line" =~ ^[[:space:]][[:space:]]-[[:space:]]+(.+)$ && -n "$current_list" ]]; then
        item=${BASH_REMATCH[1]}
        if [[ ${#item} -ge 2 && ( ( ${item:0:1} == '"' && ${item: -1} == '"' ) || ( ${item:0:1} == "'" && ${item: -1} == "'" ) ) ]]; then
          item=${item:1:${#item}-2}
        fi
        if [[ "$current_list" == tags ]]; then DOC_TAGS+=("$item"); else DOC_KEYWORDS+=("$item"); fi
        continue
      fi

      [[ -n "${line//[[:space:]]/}" ]] && add_error "$file:$line_no: unsupported frontmatter syntax"
      continue
    fi

    if [[ -z "$DOC_H1" && "$line" =~ ^#[[:space:]]+(.+)$ ]]; then
      DOC_H1=${BASH_REMATCH[1]}
      DOC_H1=${DOC_H1%${DOC_H1##*[![:space:]]}}
    fi
  done < "$file"

  (( closed )) || add_error "$file: missing closing frontmatter delimiter"
  (( seen_description )) || add_error "$file: missing metadata field description"
  (( seen_tags )) || add_error "$file: missing metadata field tags"
  (( seen_keywords )) || add_error "$file: missing metadata field keywords"

  if [[ -z "$DOC_H1" || "$DOC_H1" == *'['* ]]; then
    add_error "$file: first H1 is empty or still contains a template placeholder"
  fi
  if [[ ! "$(basename "$file")" =~ ^[a-z0-9]+(-[a-z0-9]+)*_(en|id)\.md$ ]]; then
    add_error "$file: filename must use lowercase kebab-case and end in _en.md or _id.md"
  fi
  if (( ${#DOC_DESCRIPTION} < 40 || ${#DOC_DESCRIPTION} > 300 )) || [[ "$DOC_DESCRIPTION" == *'['* ]]; then
    add_error "$file: description must be 40–300 characters without placeholders"
  fi

  validate_list "$file" tags 2 6 "${DOC_TAGS[@]}"
  validate_list "$file" keywords 4 15 "${DOC_KEYWORDS[@]}"
  for value in "${DOC_KEYWORDS[@]}"; do
    case "$(lowercase "$value")" in
      guide|documentation|software|'best practice'|docs)
        add_error "$file: keyword is too generic: $value"
        ;;
    esac
  done
}

tags_for() {
  local file=$1 line='' in_frontmatter=0 in_tags=0 item
  while IFS= read -r line || [[ -n "$line" ]]; do
    line=${line%$'\r'}
    if [[ "$line" == '---' ]]; then
      if (( in_frontmatter )); then break; else in_frontmatter=1; continue; fi
    fi
    (( in_frontmatter )) || continue
    if [[ "$line" =~ ^tags:[[:space:]]*$ ]]; then in_tags=1; continue; fi
    if [[ "$line" =~ ^[a-z][a-z0-9_-]*: ]]; then in_tags=0; continue; fi
    if (( in_tags )) && [[ "$line" =~ ^[[:space:]][[:space:]]-[[:space:]]+(.+)$ ]]; then
      item=${BASH_REMATCH[1]}
      item=${item#\"}; item=${item%\"}; item=${item#\'}; item=${item%\'}
      printf '%s\n' "$item"
    fi
  done < "$file"
}

validate_pair() {
  local file=$1 counterpart left right
  if [[ "$file" =~ _en\.md$ ]]; then
    counterpart=${file%_en.md}_id.md
  elif [[ "$file" =~ _id\.md$ ]]; then
    counterpart=${file%_id.md}_en.md
  else
    add_error "$file: topic filename must end in _en.md or _id.md"
    return
  fi
  if [[ ! -f "$counterpart" ]]; then
    add_error "$file: missing bilingual counterpart $counterpart"
    return
  fi
  left=$(tags_for "$file" | LC_ALL=C sort)
  right=$(tags_for "$counterpart" | LC_ALL=C sort)
  [[ "$left" == "$right" ]] || add_error "$file: tags must match bilingual counterpart"
}

if (( $# == 0 )); then
  printf 'Usage: validate-docs.sh <file-or-directory> [...]\n' >&2
  exit 2
fi

for input in "$@"; do collect_path "$input"; done

# Include counterparts in validation even when the caller supplies only one locale.
initial_count=${#FILES[@]}
for ((index = 0; index < initial_count; index++)); do
  file=${FILES[$index]}
  if [[ "$file" =~ _en\.md$ ]]; then
    counterpart=${file%_en.md}_id.md
  elif [[ "$file" =~ _id\.md$ ]]; then
    counterpart=${file%_id.md}_en.md
  else
    continue
  fi
  [[ -f "$counterpart" ]] && add_file "$counterpart"
done

for file in "${FILES[@]}"; do parse_document "$file" || true; done
for file in "${FILES[@]}"; do validate_pair "$file"; done

if (( ${#ERRORS[@]} > 0 )); then
  for message in "${ERRORS[@]}"; do printf 'ERROR %s\n' "$message" >&2; done
  exit 1
fi

printf 'Validated %d documentation file(s).\n' "${#FILES[@]}"
