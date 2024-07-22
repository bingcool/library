disk_partitions() {
  local dfCommand=$(type -P df)

  result=$($dfCommand -Ph | awk 'NR>1 {print "{\"fileSystem\": \"" $1 "\", \"size\": \"" $2 "\", \"used\": \"" $3 "\", \"avail\": \"" $4 "\", \"usedPercentage\": \"" $5 "\", \"mounted\": \"" $6 "\"},"}')

  echo [ ${result%?} ]
}

disk_partitions