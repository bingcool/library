general_info() {
  local lsbRelease=$(type -P lsb_release)
  local uName=$(type -P uname)
  local hostName=$(type -P hostname)

  function displaytime {
    local T=$1
    local D=$((T/60/60/24))
    local H=$((T/60/60%24))
    local M=$((T/60%60))
    local S=$((T%60))
    [[ $D > 0 ]] && printf '%d days ' $D
    [[ $H > 0 ]] && printf '%d hours ' $H
    [[ $M > 0 ]] && printf '%d minutes ' $M
    [[ $D > 0 || $H > 0 || $M > 0 ]] && printf 'and '
    printf '%d seconds\n' $S
  }

  local lsbRelease=$($lsbRelease -ds | sed -e 's/^"//'  -e 's/"$//')
  local uname=$($uName -r | sed -e 's/^"//'  -e 's/"$//')
  local os=$(echo $lsbRelease $uname)
  local hostname=$($hostName)
  local uptime_seconds=$(cat /proc/uptime | awk '{print $1}')
  local server_time=$(date)

  echo "{ \"os\": \"$os\", \"hostname\": \"$hostname\", \"uptime\": \" $(displaytime ${uptime_seconds%.*}) \", \"serverTime\": \"$server_time\" }"
}


general_info