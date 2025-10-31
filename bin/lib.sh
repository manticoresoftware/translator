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
	MAIN_BRANCH="master"
	MD5_FILE="translation.json"
	ROLE_TEMPLATE="translator.role.tpl"

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
			TRANSLATION_PARALLEL_CHUNKS=$(grep -E "^translation_parallel_chunks:" "$project_config_file" | sed 's/translation_parallel_chunks:[[:space:]]*//')
		fi
		MAIN_BRANCH=$(grep -E "^main_branch:" "$project_config_file" | sed 's/main_branch:[[:space:]]*//')
		MD5_FILE=$(grep -E "^md5_file:" "$project_config_file" | sed 's/md5_file:[[:space:]]*//')
		ROLE_TEMPLATE=$(grep -E "^role_template:" "$project_config_file" | sed 's/role_template:[[:space:]]*//')
	fi

	# Set absolute paths based on project directory
	SOURCE_DIR="$project_dir/$SOURCE_DIRECTORY"
	TARGET_DIR="$project_dir/$TARGET_DIRECTORY"
	MD5_FILE_PATH="$project_dir/$MD5_FILE"

	# Set tool paths
	DIFF_TO_YAML_CMD="$translator_dir/bin/diff-to-yaml"
	PATCH_WITH_YAML_CMD="$translator_dir/bin/patch-with-yaml"

	# Optional: enable YAML key validation during translation
	CHECK_YAML_KEYS=false
	if grep -qE "^check_yaml_keys:" "$project_config_file" 2>/dev/null; then
		CHECK_YAML_KEYS=$(grep -E "^check_yaml_keys:" "$project_config_file" | sed 's/check_yaml_keys:[[:space:]]*//')
	fi
	export CHECK_YAML_KEYS

	# Export variables
	export SOURCE_DIR
	export TARGET_DIR
	export MD5_FILE_PATH
	export TRANSLATION_CHUNK_SIZE
	export TRANSLATION_PARALLEL_CHUNKS
	export MAIN_BRANCH
	export ROLE_TEMPLATE
	export DIFF_TO_YAML_CMD
	export PATCH_WITH_YAML_CMD
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

		# If empty/non-empty status doesn't match, return failure
		if [ "$is_empty1" -ne "$is_empty2" ]; then
			return 1
		fi
	done

	# All positions match (allowing trailing empty lines to differ)
	return 0
}
sync_files() {
	local file1="$1"
	local file2="$2"

	# Check if files exist
	if [ ! -f "$file1" ] || [ ! -f "$file2" ]; then
		>&2 echo "Error: One or both files don't exist"
		return 1
	fi

	# Read file1 into array, preserving empty lines
	mapfile -t lines1 < "$file1"

	# Read file2 into array, preserving empty lines
	mapfile -t lines2 < "$file2"

	# Get non-empty lines from both files
	declare -a content=()

	for line in "${lines2[@]}"; do
		if [[ -n "$line" ]]; then
			content+=("$line")
		fi
	done

	# Synchronize file2 with file1's structure
	index=0
	for ((i=0; i<${#lines1[@]}; i++)); do
		if [[ -n "${lines1[$i]}" ]]; then
			echo "${content[$index]}"
			((index++))
		else
			echo ""
		fi
	done
}

# Get file git commit when it was lastly modified
get_git_commit() {
	local file="$1"
	local project_dir="$2"
	git -C "$project_dir" log -n 1 --pretty=format:%H -- "$file"
}

# Get commit where file was last translated (recorded in translation.json)
get_last_translation_commit() {
	local relative_path="$1"
	local project_dir="$2"

	# Find the last commit where this file's entry was modified in translation.json
	# Use -G to match the line pattern, not just string appearance
	git -C "$project_dir" log -n 1 --all --pretty=format:%H -G "\"$relative_path\":" -- "$MD5_FILE_PATH" 2>/dev/null
}

# Get commit where source file was last changed
get_last_source_file_commit() {
	local file="$1"
	local project_dir="$2"

	# Find the last commit where the source file itself was modified
	git -C "$project_dir" log -n 1 --all --pretty=format:%H -- "$file" 2>/dev/null
}

# Create a special DIFF yaml file for changes to translate and return path
create_diff_yaml_file() {
	local file="$1"
	local commit="$2"
	local project_dir="$3"

	temp_file=$(mktemp)

	# Get the diff between the commit and the current working directory file
	# If working directory is clean and matches HEAD, compare commit to HEAD instead
	local diff_output
	if git -C "$project_dir" diff --quiet HEAD -- "$file" 2>/dev/null; then
		# Working directory matches HEAD, so compare commit to HEAD
		diff_output=$(git -C "$project_dir" diff -U0 "$commit" HEAD -- "$file" 2>/dev/null)
	else
		# Working directory has changes, compare commit to working directory
		diff_output=$(git -C "$project_dir" diff -U0 "$commit" -- "$file" 2>/dev/null)
	fi

	# If diff is empty, try comparing in the other direction (in case commit is newer)
	if [ -z "$diff_output" ] || [ -z "$(printf '%s' "$diff_output" | grep '[^[:space:]]')" ]; then
		diff_output=$(git -C "$project_dir" diff -U0 HEAD "$commit" -- "$file" 2>/dev/null)
	fi

	echo "$diff_output" | $DIFF_TO_YAML_CMD > "$temp_file"

	echo "$temp_file"
}

# Apply translation patch to the destination file
patch_translation_file() {
	local file="$1"
	local diff_file="$2"
	# Create a temporary file using mktemp
	tmp_file=$(mktemp)

	# Apply the patch to the temporary file
	$PATCH_WITH_YAML_CMD "$file" "$diff_file" > "$tmp_file"
	mv "$tmp_file" "$file"
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
	roles_dir=$(aichat --info | grep roles_dir | awk '{$1=""; print $0}' | tr -d '[:space:]')
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
	roles_dir=$(aichat --info | grep roles_dir | awk '{$1=""; print $0}' | tr -d '[:space:]')

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
