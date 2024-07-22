ram_intensive_processes() {

  local psCommand=$(type -P ps)

  result=$($psCommand axo pid,user,pmem,rss,vsz,comm --sort -pmem,-rss,-vsz \
        | head -n 15 \
        | awk 'NR>1 {print "{ \"pid\": " $1 \
                      ", \"user\": \"" $2 \
                      "\", \"memPercent\": " $3 \
                      ", \"rss\": " $4 \
                      ", \"vsz\": " $5 \
                      ", \"cmd\": \"" $6 \
                      "\"},"}')

  echo [ ${result%?} ]
}


ram_intensive_processes