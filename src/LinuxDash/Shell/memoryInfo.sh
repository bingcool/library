memory_info() {

  cat /proc/meminfo \
    | awk -F: 'BEGIN {print "{"} {print "\"" $1 "\": \"" $2 "\"," } END {print "}"}' \
    | sed 'N;$s/,\n/\n/;P;D'
}


memory_info