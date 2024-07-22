ip_addresses() {

  local ifconfigCmd=$(type -P ifconfig)
  local digCmd=$(type -P dig)

  externalIp=$($digCmd +short myip.opendns.com @resolver1.opendns.com)

  echo -n "["

  for item in $($ifconfigCmd | grep -oP "^[a-zA-Z0-9:]*(?=:)")
  do
      echo -n "{\"interface\" : \""$item"\", \"ip\" : \"$( $ifconfigCmd $item | grep "inet" | awk '{match($0,"inet (addr:)?([0-9.]*)",a)}END{ if (NR != 0){print a[2]; exit}{print "none"}}')\"}, "
  done

  echo "{ \"interface\": \"external\", \"ip\": \"$externalIp\" } ]"
}


ip_addresses