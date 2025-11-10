#!/usr/bin/env bash

# Helper functions for translation scripts

# Load configuration from project's translator.config.yaml or fallback to defaults
load_config() {
	local translator_dir="$1"
	local project_dir="$2"

	# Set global variables for directories
	export TRANSLATOR_DIR="$translator_dir"
	export PROJECT_DIR="$project_dir"

	# Default values
	SOURCE_DIRECTORY="content/english"
	TARGET_DIRECTORY="content"
	TRANSLATION_CHUNK_SIZE=6144
	TRANSLATION_PARALLEL_CHUNKS=4
	ROLE_TEMPLATE="translator.role.tpl"
	CACHE_DIRECTORY=".translation-cache"

	# Check for project-specific config first
	local project_config_file="$project_dir/translator.config.yaml"

	# If not found, check for template in translator directory
	if [ ! -f "$project_config_file" ]; then
		echo "Warning: Project configuration file not found at $project_config_file"
		echo "Using default configuration values"
	else
		echo "Using project configuration from $project_config_file"
		# Extract values from YAML using grep and sed
		SOURCE_DIRECTORY=$(grep -E "^source_directory:" "$project_config_file" | sed 's/source_directory:[[:space:]]*//')
		TARGET_DIRECTORY=$(grep -E "^target_directory:" "$project_config_file" | sed 's/target_directory:[[:space:]]*//')
		TRANSLATION_CHUNK_SIZE=$(grep -E "^translation_chunk_size:" "$project_config_file" | sed 's/translation_chunk_size:[[:space:]]*//')
		if grep -qE "^translation_parallel_chunks:" "$project_config_file"; then
			TRANSLATION_PARALLEL_CHUNKS=$(grep -E "^translation_parallel_chunks:" "$project_config_file" | sed 's/translation_parallel_chunks:[[:space:]]*//' | tr -d '[:space:]')
		fi
		if grep -qE "^translation_parallel_files:" "$project_config_file"; then
			TRANSLATION_PARALLEL_FILES=$(grep -E "^translation_parallel_files:" "$project_config_file" | sed 's/translation_parallel_files:[[:space:]]*//' | tr -d '[:space:]')
		fi
		ROLE_TEMPLATE=$(grep -E "^role_template:" "$project_config_file" | sed 's/role_template:[[:space:]]*//')
		if grep -qE "^cache_directory:" "$project_config_file"; then
			CACHE_DIRECTORY=$(grep -E "^cache_directory:" "$project_config_file" | sed 's/cache_directory:[[:space:]]*//')
		fi
	fi

	# Set absolute paths based on project directory
	SOURCE_DIR="$project_dir/$SOURCE_DIRECTORY"
	TARGET_DIR="$project_dir/$TARGET_DIRECTORY"
	CACHE_DIR="$project_dir/$CACHE_DIRECTORY"

	# Optional: enable YAML key validation during translation
	CHECK_YAML_KEYS=false
	if grep -qE "^check_yaml_keys:" "$project_config_file" 2>/dev/null; then
		CHECK_YAML_KEYS=$(grep -E "^check_yaml_keys:" "$project_config_file" | sed 's/check_yaml_keys:[[:space:]]*//')
	fi
	export CHECK_YAML_KEYS

	# Load languages from config if specified
	CONFIG_LANGUAGES=()
	if grep -qE "^languages:" "$project_config_file" 2>/dev/null; then
		# Extract languages list from YAML (handles both list and single value)
		local in_languages_section=0
		while IFS= read -r line; do
			# Check if we're entering the languages section
			if [[ "$line" =~ ^languages: ]]; then
				in_languages_section=1
				continue
			fi
			# If we hit another top-level key, stop
			if [ $in_languages_section -eq 1 ] && [[ "$line" =~ ^[a-z_]+: ]]; then
				break
			fi
			# Extract language from list item
			if [ $in_languages_section -eq 1 ]; then
				# Remove leading spaces and dashes, extract language name
				lang=$(echo "$line" | sed 's/^[[:space:]]*-[[:space:]]*//' | sed 's/^[[:space:]]*//' | sed 's/[[:space:]]*$//')
				if [ -n "$lang" ] && [[ ! "$lang" =~ ^# ]]; then
					CONFIG_LANGUAGES+=("$lang")
				fi
			fi
		done < "$project_config_file"
	fi
	export CONFIG_LANGUAGES

	# Export variables
	export SOURCE_DIR
	export TARGET_DIR
	export CACHE_DIR
	export TRANSLATION_CHUNK_SIZE
	export TRANSLATION_PARALLEL_CHUNKS
	export TRANSLATION_PARALLEL_FILES
	export ROLE_TEMPLATE
}

# Load translation models from project's translator.models.yaml or fallback to defaults
load_models() {
	local translator_dir="$1"
	local project_dir="$2"
	local models_file="$project_dir/translator.models.yaml"

	# Initialize associative array
	declare -gA ATTEMPTS

	# Check if project-specific models file exists
	if [ ! -f "$models_file" ]; then
		echo "Warning: Models configuration file not found at $models_file"
		echo "Using default translation models"

			# Default models if config file not found
			ATTEMPTS[1]="openai:gpt-4o-mini"
			ATTEMPTS[2]="claude:claude-3-5-haiku-latest"
			ATTEMPTS[3]="openai:o3-mini"
			ATTEMPTS[4]="openai:gpt-4o"
			ATTEMPTS[5]="claude:claude-3-5-sonnet-latest"
		else
			echo "Using models configuration from $models_file"

			# Extract model names and priorities using grep
			mapfile -t models < <(grep -E "^[[:space:]]*- name:" "$models_file" | sed 's/.*name:[[:space:]]*//')
			mapfile -t priorities < <(grep -E "^[[:space:]]*priority:" "$models_file" | sed 's/.*priority:[[:space:]]*//')

			# Ensure we have matching counts
			if [ ${#models[@]} -eq ${#priorities[@]} ]; then
				for i in "${!models[@]}"; do
					ATTEMPTS[${priorities[$i]}]="${models[$i]}"
				done
			else
				echo "Warning: Mismatch between model names (${#models[@]}) and priorities (${#priorities[@]}) in $models_file"
				echo "Using default models"
				ATTEMPTS[1]="openai:gpt-4o-mini"
				ATTEMPTS[2]="claude:claude-3-5-haiku-latest"
				ATTEMPTS[3]="openai:o3-mini"
				ATTEMPTS[4]="openai:gpt-4o"
				ATTEMPTS[5]="claude:claude-3-5-sonnet-latest"
			fi
			fi

	# Check if we have any models loaded
	if [ ${#ATTEMPTS[@]} -eq 0 ]; then
		echo "Warning: No translation models loaded, using defaults"
		ATTEMPTS[1]="openai:gpt-4o-mini"
		ATTEMPTS[2]="claude:claude-3-5-haiku-latest"
		ATTEMPTS[3]="openai:o3-mini"
		ATTEMPTS[4]="openai:gpt-4o"
		ATTEMPTS[5]="claude:claude-3-5-sonnet-latest"
	fi

	# Log the models being used
	echo "Translation models (in priority order):"
	for priority in $(echo ${!ATTEMPTS[@]} | tr ' ' '\n' | sort -n); do
		echo "  $priority: ${ATTEMPTS[$priority]}"
	done

	export ATTEMPTS
}
# Optimized function to check if two files have matching line structure
# Returns 0 if lines match (empty/non-empty at same positions), 1 otherwise
# More lenient: allows differences in trailing empty lines
check_line_structure_match() {
	local file1="$1"
	local file2="$2"

	# Check if files exist
	if [ ! -f "$file1" ] || [ ! -f "$file2" ]; then
		return 1
	fi

	# Read both files into arrays
	mapfile -t lines1 < "$file1"
	mapfile -t lines2 < "$file2"

	# Find the last non-empty line in each file
	local last_nonempty1=-1
	local last_nonempty2=-1
	for ((i=${#lines1[@]}-1; i>=0; i--)); do
		if [[ -n "${lines1[$i]}" ]] && [[ ! "${lines1[$i]}" =~ ^[[:space:]]*$ ]]; then
			last_nonempty1=$i
			break
		fi
	done
	for ((i=${#lines2[@]}-1; i>=0; i--)); do
		if [[ -n "${lines2[$i]}" ]] && [[ ! "${lines2[$i]}" =~ ^[[:space:]]*$ ]]; then
			last_nonempty2=$i
			break
		fi
	done

	# If neither file has non-empty lines, they match
	if [ $last_nonempty1 -eq -1 ] && [ $last_nonempty2 -eq -1 ]; then
		return 0
	fi

	# Compare up to the maximum of the two last non-empty lines
	# This allows trailing empty lines to differ
	local max_compare=$((last_nonempty1 > last_nonempty2 ? last_nonempty1 : last_nonempty2))

	# Count mismatches - allow a small number of mismatches for chunk-level checking
	# (Full file structure is enforced by sync_files)
	local mismatch_count=0
	local total_lines=$((max_compare + 1))
	# Allow ~30% mismatches for chunk-level checking (very lenient)
	# This is because AI may reformat text slightly, but sync_files will enforce
	# exact line structure at the file level at the end
	local allowed_mismatches=$((total_lines * 3 / 10 + 1))

	# Compare line by line for empty/non-empty pattern
	for ((i=0; i<=max_compare; i++)); do
		local is_empty1=0
		local is_empty2=0

		# Check if line1 is empty (only whitespace or truly empty, or beyond array)
		if [ $i -ge ${#lines1[@]} ]; then
			is_empty1=1
		elif [[ -z "${lines1[$i]}" ]] || [[ "${lines1[$i]}" =~ ^[[:space:]]*$ ]]; then
			is_empty1=1
		fi

		# Check if line2 is empty (only whitespace or truly empty, or beyond array)
		if [ $i -ge ${#lines2[@]} ]; then
			is_empty2=1
		elif [[ -z "${lines2[$i]}" ]] || [[ "${lines2[$i]}" =~ ^[[:space:]]*$ ]]; then
			is_empty2=1
		fi

		# If empty/non-empty status doesn't match, count it
		if [ "$is_empty1" -ne "$is_empty2" ]; then
			((mismatch_count++))
			# Debug output when mismatch detected
			if [ -n "$DEBUG_LINE_STRUCTURE" ]; then
				echo "Line structure mismatch at line $((i+1)):" >&2
				echo "  File1 (empty=$is_empty1): ${lines1[$i]:-'(beyond array)'}" >&2
				echo "  File2 (empty=$is_empty2): ${lines2[$i]:-'(beyond array)'}" >&2
				echo "  Last non-empty: file1=$last_nonempty1, file2=$last_nonempty2" >&2
			fi
		fi
	done

	# Allow a small number of mismatches (will be fixed by sync_files at file level)
	if [ $mismatch_count -le $allowed_mismatches ]; then
		return 0
	else
		if [ -n "$DEBUG_LINE_STRUCTURE" ]; then
			echo "Too many mismatches: $mismatch_count (allowed: $allowed_mismatches, total lines: $total_lines)" >&2
		fi
		return 1
	fi
}
sync_files() {
	local file1="$1"
	local file2="$2"

	# Check if files exist
	if [ ! -f "$file1" ] || [ ! -f "$file2" ]; then
		>&2 echo "Error: One or both files don't exist"
		return 1
	fi

	# Read file1 into array, preserving all lines including whitespace-only
	mapfile -t lines1 < "$file1"

	# Read file2 into array, preserving all lines including whitespace-only
	mapfile -t lines2 < "$file2"

	# Get non-whitespace lines from file2 (lines that have actual content)
	# Exclude code block lines (they will be preserved from source)
	declare -a content=()

	for line in "${lines2[@]}"; do
		# Skip code block lines (they will be preserved from source in sync_files)
		if [[ "$line" =~ \`\`\` ]]; then
			continue
		fi
		# Skip lines that are ONLY HTML comments (with optional whitespace)
		# Lines that contain HTML comments but also have other content should be translated
		local trimmed_line=$(echo "$line" | sed 's/^[[:space:]]*//' | sed 's/[[:space:]]*$//')
		if [[ "$trimmed_line" =~ ^\<!--.*--\>$ ]] || [[ "$trimmed_line" =~ ^\<!--[[:space:]]*$ ]]; then
			continue
		fi
		# Only skip lines that are truly empty or whitespace-only
		if [[ -n "$line" ]] && [[ ! "$line" =~ ^[[:space:]]*$ ]]; then
			content+=("$line")
		fi
	done

	# Synchronize file2 with file1's structure
	# Preserve whitespace-only lines exactly as they appear in file1
	# Check if source file ends with newline
	local source_ends_with_newline=true
	if [ -s "$file1" ]; then
		# Use hexdump to check last byte - more reliable than string comparison
		local last_byte=$(tail -c 1 "$file1" 2>/dev/null | od -An -tx1 | tr -d ' \n')
		if [ "$last_byte" != "0a" ]; then
			source_ends_with_newline=false
		fi
	fi
	
	index=0
	for ((i=0; i<${#lines1[@]}; i++)); do
		local line1="${lines1[$i]}"
		local is_last_line=$((i == ${#lines1[@]} - 1))
		local is_empty_or_whitespace=0
		local is_code_block_line=0
		local is_html_comment_line=0
		
		# Check if line1 contains code block markers (```)
		if [[ "$line1" =~ \`\`\` ]]; then
			is_code_block_line=1
		fi
		
		# Check if line1 is ONLY an HTML comment (with optional whitespace)
		# Lines that contain HTML comments but also have other content should be translated
		local trimmed_line1=$(echo "$line1" | sed 's/^[[:space:]]*//' | sed 's/[[:space:]]*$//')
		if [[ "$trimmed_line1" =~ ^\<!--.*--\>$ ]] || [[ "$trimmed_line1" =~ ^\<!--[[:space:]]*$ ]]; then
			is_html_comment_line=1
		fi
		
		# Check if line1 is empty or whitespace-only
		if [[ -z "$line1" ]] || [[ "$line1" =~ ^[[:space:]]*$ ]]; then
			is_empty_or_whitespace=1
		fi
		
		if [ "$is_code_block_line" -eq 1 ] || [ "$is_html_comment_line" -eq 1 ]; then
			# This is a code block or HTML comment line - preserve exactly from source (file1)
			if [ "$is_last_line" -eq 1 ] && [ "$source_ends_with_newline" = false ]; then
				printf "%s" "$line1"
			else
				echo "$line1"
			fi
			# Don't increment content index for code block or HTML comment lines
		elif [ "$is_empty_or_whitespace" -eq 0 ]; then
			# This is a content line - use translated content
			if [ $index -lt ${#content[@]} ]; then
				if [ "$is_last_line" -eq 1 ] && [ "$source_ends_with_newline" = false ]; then
					# Last line and source doesn't end with newline - don't add newline
					printf "%s" "${content[$index]}"
				else
					echo "${content[$index]}"
				fi
			else
				# Fallback if content array is shorter (shouldn't happen)
				if [ "$is_last_line" -eq 1 ] && [ "$source_ends_with_newline" = false ]; then
					printf "%s" "$line1"
				else
					echo "$line1"
				fi
			fi
			((index++))
		else
			# This is an empty or whitespace-only line - preserve exactly from file1
			# Output empty/whitespace lines - they should always have a newline
			# unless it's the absolute last character of the file and file doesn't end with newline
			if [ "$is_last_line" -eq 1 ] && [ "$source_ends_with_newline" = false ]; then
				# Last line, source doesn't end with newline - output without newline
				printf "%s" "$line1"
			else
				# Output the line with newline (preserving whitespace)
				# This handles both empty lines and whitespace-only lines
				printf "%s\n" "$line1"
			fi
		fi
	done
}

# Validate translated file structure matches source file
validate_translation() {
	local source_file="$1"
	local target_file="$2"
	
	# Check if files exist
	if [ ! -f "$source_file" ] || [ ! -f "$target_file" ]; then
		echo "Error: One or both files don't exist for validation" >&2
		return 1
	fi
	
	# Read both files into arrays
	mapfile -t source_lines < "$source_file"
	mapfile -t target_lines < "$target_file"
	
	# 1. Check line counts match
	if [ ${#source_lines[@]} -ne ${#target_lines[@]} ]; then
		echo "Error: Line count mismatch - source: ${#source_lines[@]}, target: ${#target_lines[@]}" >&2
		return 1
	fi
	
	local validation_failed=0
	
	# 2. Check positions of lines containing ``` match
	declare -a source_code_block_lines=()
	declare -a target_code_block_lines=()
	
	for i in "${!source_lines[@]}"; do
		if [[ "${source_lines[$i]}" =~ \`\`\` ]]; then
			source_code_block_lines+=("$i")
		fi
		if [[ "${target_lines[$i]}" =~ \`\`\` ]]; then
			target_code_block_lines+=("$i")
		fi
	done
	
	if [ ${#source_code_block_lines[@]} -ne ${#target_code_block_lines[@]} ]; then
		echo "Error: Code block line count mismatch - source: ${#source_code_block_lines[@]}, target: ${#target_code_block_lines[@]}" >&2
		validation_failed=1
	else
		for idx in "${!source_code_block_lines[@]}"; do
			if [ "${source_code_block_lines[$idx]}" != "${target_code_block_lines[$idx]}" ]; then
				echo "Error: Code block position mismatch at line $((source_code_block_lines[$idx] + 1))" >&2
				validation_failed=1
			fi
		done
	fi
	
	# 3. Check positions of empty lines match
	declare -a source_empty_lines=()
	declare -a target_empty_lines=()
	
	for i in "${!source_lines[@]}"; do
		# Check if line is empty (no content, may have whitespace)
		if [[ -z "${source_lines[$i]}" ]] || [[ "${source_lines[$i]}" =~ ^[[:space:]]*$ ]]; then
			source_empty_lines+=("$i")
		fi
		if [[ -z "${target_lines[$i]}" ]] || [[ "${target_lines[$i]}" =~ ^[[:space:]]*$ ]]; then
			target_empty_lines+=("$i")
		fi
	done
	
	if [ ${#source_empty_lines[@]} -ne ${#target_empty_lines[@]} ]; then
		echo "Error: Empty line count mismatch - source: ${#source_empty_lines[@]}, target: ${#target_empty_lines[@]}" >&2
		validation_failed=1
	else
		for idx in "${!source_empty_lines[@]}"; do
			if [ "${source_empty_lines[$idx]}" != "${target_empty_lines[$idx]}" ]; then
				echo "Error: Empty line position mismatch at line $((source_empty_lines[$idx] + 1))" >&2
				validation_failed=1
			fi
		done
	fi
	
	# 4. Check positions and contents of HTML comments match
	# Only check lines that are ONLY HTML comments (with optional whitespace)
	# Lines that contain HTML comments but also have other content should be translated
	declare -a source_comment_lines=()
	declare -a target_comment_lines=()
	declare -a source_comment_contents=()
	declare -a target_comment_contents=()
	
	for i in "${!source_lines[@]}"; do
		# Check if line is ONLY an HTML comment (with optional whitespace)
		local source_trimmed=$(echo "${source_lines[$i]}" | sed 's/^[[:space:]]*//' | sed 's/[[:space:]]*$//')
		if [[ "$source_trimmed" =~ ^\<!--.*--\>$ ]] || [[ "$source_trimmed" =~ ^\<!--[[:space:]]*$ ]]; then
			source_comment_lines+=("$i")
			source_comment_contents+=("${source_lines[$i]}")
		fi
		local target_trimmed=$(echo "${target_lines[$i]}" | sed 's/^[[:space:]]*//' | sed 's/[[:space:]]*$//')
		if [[ "$target_trimmed" =~ ^\<!--.*--\>$ ]] || [[ "$target_trimmed" =~ ^\<!--[[:space:]]*$ ]]; then
			target_comment_lines+=("$i")
			target_comment_contents+=("${target_lines[$i]}")
		fi
	done
	
	if [ ${#source_comment_lines[@]} -ne ${#target_comment_lines[@]} ]; then
		echo "Error: HTML comment line count mismatch - source: ${#source_comment_lines[@]}, target: ${#target_comment_lines[@]}" >&2
		validation_failed=1
	else
		for idx in "${!source_comment_lines[@]}"; do
			if [ "${source_comment_lines[$idx]}" != "${target_comment_lines[$idx]}" ]; then
				echo "Error: HTML comment position mismatch at line $((source_comment_lines[$idx] + 1))" >&2
				validation_failed=1
			elif [ "${source_comment_contents[$idx]}" != "${target_comment_contents[$idx]}" ]; then
				echo "Error: HTML comment content mismatch at line $((source_comment_lines[$idx] + 1))" >&2
				echo "  Source: ${source_comment_contents[$idx]}" >&2
				echo "  Target: ${target_comment_contents[$idx]}" >&2
				validation_failed=1
			fi
		done
	fi
	
	if [ $validation_failed -eq 1 ]; then
		return 1
	fi
	
	return 0
}

# Calculate hash of a text block for caching
calculate_block_hash() {
	local block="$1"
	printf "%s" "$block" | sha256sum | cut -d' ' -f1
}

# Initialize cache directory if it doesn't exist
init_cache() {
	if [ ! -d "$CACHE_DIR" ]; then
		mkdir -p "$CACHE_DIR"
	fi
}

# Get cache file path for a specific document
get_cache_file_path() {
	local relative_path="$1"
	# Convert relative path to cache file path (e.g., docs/file.md -> cache/docs/file.md.json)
	local cache_file="$CACHE_DIR/$relative_path.json"
	# Ensure directory exists
	local cache_dir=$(dirname "$cache_file")
	mkdir -p "$cache_dir"
	echo "$cache_file"
}

# Remove all cache entries for a specific file
clear_file_cache() {
	local relative_path="$1"
	
	if [ -z "$relative_path" ]; then
		return 1
	fi
	
	init_cache
	
	local cache_file=$(get_cache_file_path "$relative_path")
	local lock_dir="${cache_file}.lock"
	
	# Try to acquire lock with timeout (10 seconds)
	if ! acquire_lock "$lock_dir" 10; then
		echo "Warning: Could not acquire lock for cache file $cache_file after 10 seconds" >&2
		return 1
	fi
	
	# Lock acquired - proceed with cache deletion
	# Use trap to ensure lock is released even on error
	trap "release_lock '$lock_dir'" EXIT
	
	# Remove cache file if it exists
	if [ -f "$cache_file" ]; then
		rm -f "$cache_file"
	fi
	
	# Release lock
	release_lock "$lock_dir"
	trap - EXIT
	
	return 0
}

# Remove a specific cache entry for a block hash and language
clear_cache_entry() {
	local block_hash="$1"
	local language="$2"
	local relative_path="$3"
	
	if [ -z "$relative_path" ] || [ -z "$block_hash" ] || [ -z "$language" ]; then
		return 1
	fi
	
	init_cache
	
	local cache_file=$(get_cache_file_path "$relative_path")
	local lock_dir="${cache_file}.lock"
	
	# Try to acquire lock with timeout (10 seconds)
	if ! acquire_lock "$lock_dir" 10; then
		echo "Warning: Could not acquire lock for cache file $cache_file after 10 seconds" >&2
		return 1
	fi
	
	# Lock acquired - proceed with cache update
	# Use trap to ensure lock is released even on error
	trap "release_lock '$lock_dir'" EXIT
	
	# Load existing cache or create empty
	local temp_json=$(mktemp)
	if [ -f "$cache_file" ]; then
		cp "$cache_file" "$temp_json"
	else
		echo "{}" > "$temp_json"
	fi
	
	# Remove the specific language translation from the cache entry
	local temp_output=$(mktemp)
	jq --arg hash "$block_hash" \
	   --arg lang "$language" \
	   'if has($hash) then
			.[$hash].translations = (.[$hash].translations // {} | del(.[$lang]))
		else
			.
		end' "$temp_json" > "$temp_output" 2>/dev/null
	
	if [ $? -eq 0 ] && [ -s "$temp_output" ]; then
		if jq empty "$temp_output" 2>/dev/null; then
			# Atomic write
			local cache_dir=$(dirname "$cache_file")
			local temp_final="${cache_dir}/cache.$$.$(date +%s%N 2>/dev/null || date +%s).json"
			local retry_count=0
			while [ -f "$temp_final" ] && [ $retry_count -lt 10 ]; do
				temp_final="${cache_dir}/cache.$$.$(date +%s%N 2>/dev/null || date +%s).${retry_count}.json"
				((retry_count++))
			done
			
			cp "$temp_output" "$temp_final"
			mv "$temp_final" "$cache_file"
			rm -f "$temp_json" "$temp_output"
			release_lock "$lock_dir"
			trap - EXIT
			return 0
		fi
	fi
	
	rm -f "$temp_json" "$temp_output"
	release_lock "$lock_dir"
	trap - EXIT
	return 1
}

# Get cached translation for a block (from per-document cache)
get_cached_translation() {
	local block_hash="$1"
	local language="$2"
	local relative_path="$3"
	
	if [ -n "$relative_path" ]; then
		local cache_file=$(get_cache_file_path "$relative_path")
		if [ -f "$cache_file" ]; then
			local cached=$(jq -r ".[\"$block_hash\"].translations[\"$language\"] // empty" "$cache_file" 2>/dev/null)
			if [ -n "$cached" ] && [ "$cached" != "null" ] && [ "$cached" != "" ]; then
				printf "%s" "$cached"
				return 0
			fi
		fi
	fi
	
	return 1
}

# Check if block is a code block or comment (should not be translated)
is_code_block_or_comment() {
	local block="$1"
	
	# Check if it's a code block (starts and ends with ```)
	if [[ "$block" =~ ^\`\`\`.*\`\`\`$ ]] || [[ "$block" =~ ^\`\`\` ]]; then
		return 0
	fi
	
	# Check if it's a comment (HTML comment or markdown comment)
	if [[ "$block" =~ ^[[:space:]]*\<!--.*--\>[[:space:]]*$ ]] || [[ "$block" =~ ^[[:space:]]*\<!-- ]]; then
		return 0
	fi
	
	# Check if it contains CODE_BLOCK_N placeholder (should not be translated as a whole)
	# Note: This is handled differently - chunks with CODE_BLOCK_N are still translated,
	# but the placeholders themselves must be preserved by the AI
	if [[ "$block" =~ CODE_BLOCK_[0-9]+ ]]; then
		# This is not a pure code block, but contains placeholders
		# The AI should preserve these placeholders during translation
		return 1
	fi
	
	return 1
}

# Acquire file lock using atomic mkdir operation (portable across Linux and macOS)
acquire_lock() {
	local lock_dir="$1"
	local timeout_seconds="${2:-10}"
	local start_time=$(date +%s)
	
	while true; do
		# Try to create lock directory atomically (mkdir is atomic on most filesystems)
		if mkdir "$lock_dir" 2>/dev/null; then
			# Lock acquired - write PID to lock file for stale lock detection
			echo $$ > "${lock_dir}/pid"
			return 0
		fi
		
		# Lock directory exists - check if it's stale
		if [ -d "$lock_dir" ]; then
			local pid_file="${lock_dir}/pid"
			if [ -f "$pid_file" ]; then
				local lock_pid=$(cat "$pid_file" 2>/dev/null)
				# Check if the process that created the lock is still running
				if [ -n "$lock_pid" ] && ! kill -0 "$lock_pid" 2>/dev/null; then
					# Process is dead, remove stale lock
					rm -rf "$lock_dir" 2>/dev/null
					continue
				fi
			else
				# No PID file, check directory age
				local lock_age=$(($(date +%s) - $(stat -f %m "$lock_dir" 2>/dev/null || stat -c %Y "$lock_dir" 2>/dev/null || echo 0)))
				if [ $lock_age -gt 60 ]; then
					# Stale lock (older than 60 seconds), remove it
					rm -rf "$lock_dir" 2>/dev/null
					continue
				fi
			fi
		fi
		
		# Check timeout
		local current_time=$(date +%s)
		if [ $((current_time - start_time)) -ge $timeout_seconds ]; then
			return 1
		fi
		
		# Wait a bit before retrying
		sleep 0.1
	done
}

# Release file lock
release_lock() {
	local lock_dir="$1"
	rm -rf "$lock_dir" 2>/dev/null
}

# Save translation to cache (per-document cache)
save_to_cache() {
	local block_hash="$1"
	local original_block="$2"
	local language="$3"
	local translated_block="$4"
	local is_code_or_comment="$5"
	local relative_path="$6"
	
	if [ -z "$relative_path" ]; then
		return 1
	fi
	
	init_cache
	
	local cache_file=$(get_cache_file_path "$relative_path")
	local lock_dir="${cache_file}.lock"
	
	# Try to acquire lock with timeout (10 seconds)
	if ! acquire_lock "$lock_dir" 10; then
		echo "Warning: Could not acquire lock for cache file $cache_file after 10 seconds" >&2
		return 1
	fi
	
	# Lock acquired - proceed with cache update
	# Use trap to ensure lock is released even on error
	trap "release_lock '$lock_dir'" EXIT
	
	# Load existing cache or create empty
	local temp_json=$(mktemp)
	if [ -f "$cache_file" ]; then
		cp "$cache_file" "$temp_json"
	else
		echo "{}" > "$temp_json"
	fi
	
	local translation_to_save="$translated_block"
	if [ "$is_code_or_comment" = "true" ]; then
		translation_to_save="$original_block"
	fi
	
	# Update JSON using jq - ensure output is valid JSON
	local temp_output=$(mktemp)
	jq --arg hash "$block_hash" \
	   --arg original "$original_block" \
	   --arg lang "$language" \
	   --arg translation "$translation_to_save" \
	   --argjson is_code "$is_code_or_comment" \
	   '.[$hash] = {
			original: $original,
			translations: ((.[$hash] // {}).translations + {($lang): $translation}),
			is_code_or_comment: $is_code
		}' "$temp_json" > "$temp_output" 2>/dev/null
	
	if [ $? -eq 0 ] && [ -s "$temp_output" ]; then
		# Verify it's valid JSON before writing
		if jq empty "$temp_output" 2>/dev/null; then
			# Atomic write: write to temp file in same directory, then rename
			local cache_dir=$(dirname "$cache_file")
			# Use unique temp file name with PID and timestamp to avoid conflicts
			local temp_final="${cache_dir}/cache.$$.$(date +%s%N 2>/dev/null || date +%s).json"
			# Retry if temp file exists (very unlikely but possible)
			local retry_count=0
			while [ -f "$temp_final" ] && [ $retry_count -lt 10 ]; do
				temp_final="${cache_dir}/cache.$$.$(date +%s%N 2>/dev/null || date +%s).${retry_count}.json"
				((retry_count++))
			done
			
			cp "$temp_output" "$temp_final"
			mv "$temp_final" "$cache_file"
			rm -f "$temp_json" "$temp_output"
			release_lock "$lock_dir"
			trap - EXIT
			return 0
		else
			echo "Warning: Invalid JSON generated for cache" >&2
			rm -f "$temp_json" "$temp_output"
			release_lock "$lock_dir"
			trap - EXIT
			return 1
		fi
	else
		rm -f "$temp_json" "$temp_output"
		release_lock "$lock_dir"
		trap - EXIT
		return 1
	fi
}

# Create file diff using actual file comparison (not git)
create_file_diff() {
	local source_file="$1"
	local target_file="$2"
	
	if [ ! -f "$source_file" ]; then
		return 1
	fi
	
	# If target doesn't exist, entire source is new
	if [ ! -f "$target_file" ]; then
		# Return all content as additions
		local temp_diff=$(mktemp)
		local line_num=1
		while IFS= read -r line || [ -n "$line" ]; do
			echo "add${line_num}: |" >> "$temp_diff"
			echo "  $line" >> "$temp_diff"
			((line_num++))
		done < "$source_file"
		echo "$temp_diff"
		return 0
	fi
	
	# Use diff command to create unified diff, then convert to YAML format
	local diff_output=$(diff -u "$target_file" "$source_file" 2>/dev/null)
	if [ -z "$diff_output" ]; then
		# Files are identical
		return 1
	fi
	
	# Convert unified diff to our YAML format
	local temp_diff=$(mktemp)
	local in_hunk=false
	local old_line=0
	local new_line=0
	local old_start=0
	local new_start=0
	local current_add_block=""
	local current_add_line=0
	
	while IFS= read -r line || [ -n "$line" ]; do
		# Skip diff headers
		if [[ "$line" =~ ^(diff\ |\+\+\+|---|index\ ) ]]; then
			continue
		fi
		
		# Parse hunk header: @@ -old_start,old_count +new_start,new_count @@
		if [[ "$line" =~ ^@@\ -([0-9]+)(,[0-9]+)?\ \+([0-9]+)(,[0-9]+)?\ @@ ]]; then
			# Flush any pending add block before new hunk
			if [ -n "$current_add_block" ]; then
				echo "add${current_add_line}: |" >> "$temp_diff"
				echo "$current_add_block" | sed 's/^/  /' >> "$temp_diff"
				current_add_block=""
			fi
			old_start="${BASH_REMATCH[1]}"
			new_start="${BASH_REMATCH[3]}"
			old_line=$old_start
			new_line=$new_start
			in_hunk=true
			continue
		fi
		
		if [ "$in_hunk" = true ]; then
			# Addition line
			if [[ "$line" =~ ^\+ ]]; then
				# Start new add block if needed
				if [ -z "$current_add_block" ] || [ "$current_add_line" != "$new_line" ]; then
					if [ -n "$current_add_block" ]; then
						echo "add${current_add_line}: |" >> "$temp_diff"
						echo "$current_add_block" | sed 's/^/  /' >> "$temp_diff"
					fi
					current_add_line=$new_line
					current_add_block="${line:1}"
				else
					current_add_block="${current_add_block}"$'\n'"${line:1}"
				fi
				((new_line++))
			# Deletion line
			elif [[ "$line" =~ ^\- ]]; then
				# Flush any pending add block
				if [ -n "$current_add_block" ]; then
					echo "add${current_add_line}: |" >> "$temp_diff"
					echo "$current_add_block" | sed 's/^/  /' >> "$temp_diff"
					current_add_block=""
				fi
				echo "del${old_line}:" >> "$temp_diff"
				((old_line++))
			# Context line
			elif [[ "$line" =~ ^\  ]]; then
				# Flush any pending add block
				if [ -n "$current_add_block" ]; then
					echo "add${current_add_line}: |" >> "$temp_diff"
					echo "$current_add_block" | sed 's/^/  /' >> "$temp_diff"
					current_add_block=""
				fi
				((old_line++))
				((new_line++))
			fi
		fi
	done <<< "$diff_output"
	
	# Flush any remaining add block
	if [ -n "$current_add_block" ]; then
		echo "add${current_add_line}: |" >> "$temp_diff"
		echo "$current_add_block" | sed 's/^/  /' >> "$temp_diff"
	fi
	
	echo "$temp_diff"
	return 0
}

# Check if prerequisites, aichat, and jq are installed
check_prerequisites() {
	local languages="$1"
	local missing_deps=0

	# Check for required commands to be installed
	for command in aichat jq yq; do
		if ! command -v "$command" &> /dev/null; then
			echo "Error: $command is not installed or not in PATH"
			missing_deps=1
		fi
	done
	
	# Exit if any dependencies are missing
	if [ $missing_deps -eq 1 ]; then
		echo "Please install missing dependencies and try again"
		exit 1
	fi

	# Check if roles are installed
	# Extract roles_dir, preserving spaces in path (e.g., "Application Support")
	roles_dir=$(aichat --info | grep roles_dir | sed 's/roles_dir[[:space:]]*//' | sed 's/^[[:space:]]*//')
	# It may be missed in the system, so create then
	if [ ! -d "$roles_dir" ]; then
		mkdir -p "$roles_dir"
	fi

	for language in "${languages[@]}"; do
		role_file="$roles_dir/$PROJECT_NAME-translate-to-$language.md"
		if [ ! -f "$role_file" ]; then
			echo "Installing translation role for $language"
			LANGUAGE=$language envsubst < "$ROLE_TEMPLATE" > "$role_file"
		fi
	done

	echo "All required dependencies are installed"
	return 0
}

# Check and install translation roles
check_translation_roles() {
	local missing_roles=0
	# Extract roles_dir, preserving spaces in path (e.g., "Application Support")
	roles_dir=$(aichat --info | grep roles_dir | sed 's/roles_dir[[:space:]]*//' | sed 's/^[[:space:]]*//')

	# Check if project role template exists
	local role_template_path="$PROJECT_DIR/$ROLE_TEMPLATE"
	if [ ! -f "$role_template_path" ]; then
		echo "Warning: Role template not found at $role_template_path"
		role_template_path="$TRANSLATOR_DIR/config/translator.role.tpl"
		if [ ! -f "$role_template_path" ]; then
			echo "Error: Default role template not found at $role_template_path"
			return 1
		fi
		echo "Using default role template from $role_template_path"
	else
		echo "Using project role template from $role_template_path"
	fi

	for language in "${languages[@]}"; do
		role_file="$roles_dir/$PROJECT_NAME-translate-to-$language.md"
		if [ ! -f "$role_file" ]; then
			echo "Installing translation role for $language"
			LANGUAGE=$language envsubst < "$role_template_path" > "$role_file"
		fi
	done

	return $missing_roles
}
