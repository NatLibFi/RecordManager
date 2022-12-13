 #!/bin/bash

if [ "$1" == "" ]; then
  echo "Usage: $0 <Skosmos ttl download url>"
  echo
  echo "Updates the linked data enrichment collection with data retrieved from a Skosmos server"
  echo
  echo "Example: $0 https://api.finto.fi/rest/v1/yso/data"
  echo
  exit
fi

set -e

DIR=$(dirname "$0")
URL=$1
DATABASE=$2
COLLECTION=$3
QFILE=$4
for TMPDIR in "$TMPDIR" "$TMP" /var/tmp /tmp
do
    test -d "$TMPDIR" && break
done

curl -s -S -f -L -X GET -H 'Accept: text/turtle' ${URL} > ${TMPDIR}/ld_fetch_result.ttl

${DIR}/../console -q util:import-rdf ${TMPDIR}/ld_fetch_result.ttl
rm ${TMPDIR}/ld_fetch_result.ttl
