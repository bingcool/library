load_avg() {

  local numberOfCores=$(grep -c 'processor' /proc/cpuinfo)

  if [ $numberOfCores -eq 0 ]; then
    numberOfCores=1
  fi

  result=$(cat /proc/loadavg | awk '{print "{ \"minAvg1\": " ($1*100)/'$numberOfCores' ", \"minAvg5\": " ($2*100)/'$numberOfCores' ", \"minAvg15\": " ($3*100)/'$numberOfCores' "}," }')

  echo ${result%?}
}


load_avg