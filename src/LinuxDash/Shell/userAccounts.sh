user_accounts() {
  result=$(awk -F: '{ \
          if ($3<=499){userType="system";} \
          else {userType="user";} \
          print "{ \"type\": \"" userType "\"" ", \"user\": \"" $1 "\", \"home\": \"" $6 "\" }," }' < /etc/passwd
      )

  length=$(echo ${#result})

  if [ $length -eq 0 ]; then
    result=$(getent passwd | awk -F: '{ if ($3<=499){userType="system";} else {userType="user";} print "{ \"type\": \"" userType "\"" ", \"user\": \"" $1 "\", \"home\": \"" $6 "\" }," }')
  fi

  echo [ ${result%?} ]
}


user_accounts