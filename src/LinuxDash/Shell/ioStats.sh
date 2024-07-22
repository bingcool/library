io_stats() {

  result=$(cat /proc/diskstats | awk \
          '{ if($4==0 && $8==0 && $12==0 && $13==0) next } \
          {print "{ \"device\": \"" $3 "\", \"reads\": \""$4"\", \"writes\": \"" $8 "\", \"inProg.\": \"" $12 "\", \"time\": \"" $13 "\"},"}'
      )

  echo [ ${result%?} ]
}


io_stats