cpu_info() {
  local lscpuCommand=$(type -P lscpu)

  result=$($lscpuCommand \
      | awk -F: '{print "\""$1"\": \""$2"\"," }  '\
      )

  echo "{" ${result%?} "}"
}

cpu_info
