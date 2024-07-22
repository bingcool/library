current_ram() {

  local memInfoFile="/proc/meminfo"

  memInfo=$(cat $memInfoFile | grep 'MemTotal\|MemFree\|Buffers\|Cached')

  echo $memInfo | awk '{print "{ \"total\": " ($2/1024) ", \"used\": " ( ($2-($5+$8+$11))/1024 ) ", \"available\": " (($5+$8+$11)/1024) " }"  }'
}

current_ram