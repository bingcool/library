cpu_intensive_processes() {

  result=$(ps axo pid,user,pcpu,rss,vsz,comm --sort -pcpu,-rss,-vsz \
        | head -n 15 \
        | awk 'BEGIN{OFS=":"} NR>1 {print "{ \"pid\": " $1 \
                ", \"user\": \"" $2 "\"" \
                ", \"usage\": " $3 \
                ", \"rss\": " $4 \
                ", \"vsz\": " $5 \
                ", \"cmd\": \"" $6 "\"" "},"\
              }')

  echo "[" ${result%?} "]"
}


cpu_intensive_processes