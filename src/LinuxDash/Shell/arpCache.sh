arp_cache() {
  local arpCommand=$(type -P arp)

  result=$($arpCommand | awk 'BEGIN {print "["} NR>1 \
              {print "{ \"addr\": \"" $1 "\", " \
                    "\"hwType\": \"" $2 "\", " \
                    "\"hwAddr.\": \"" $3 "\", " \
                    "\"mask\": \"" $5 "\" }, " \
                    } \
            END {print "]"}' \
        | sed 'N;$s/},/}/;P;D')

  if [ -z "$result" ]; then
    echo {}
  else
    echo $result
  fi
}


arp_cache