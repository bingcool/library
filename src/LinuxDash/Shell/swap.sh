swap() {

  local wcCmd=$(which wc)

  local swapLineCount=$(cat /proc/swaps | $wcCmd -l)

  if [ "$swapLineCount" -gt 1 ]; then

    result=$(cat /proc/swaps \
        | awk 'NR>1 {print "{ \"filename\": \"" $1"\", \"type\": \""$2"\", \"size\": \""$3"\", \"used\": \""$4"\", \"priority\": \""$5"\"}," }'
      )

    echo [ ${result%?} ]

  else
    echo []
  fi
}


swap