 #!/bin/bash -e

if [ "$4" == "" ]; then
  echo "Usage: $0 <SPARQL endpoint url> <Mongo database> <Mongo collection> <query file>"
  echo
  echo "Updates the ontology enrichment collection with data retrieved from a SPARQL server"
  echo
  echo "The last parameter should contain the query that results in a proper csv file."
  echo "See https://github.com/NatLibFi/Finto-data/blob/master/conf/finto.fi/config.ttl"
  echo "for the sparqlEndpoint addresses for different vocabularies if querying Finto."
  echo
  echo "Example: $0 https://api.finto.fi/sparql recman ontologyEnrichment fetch_finto_koko.post"
  echo
  exit
fi

DIR=$(dirname "$0")
URL=$1
DATABASE=$2
COLLECTION=$3
QFILE=$4
for TMPDIR in "$TMPDIR" "$TMP" /var/tmp /tmp
do
    test -d "$TMPDIR" && break
done

curl -s -H "Accept: text/csv" --data @${DIR}/${QFILE} ${URL} > ${TMPDIR}/fetch_result.csv
FIRSTLINE=`head -1 ${TMPDIR}/fetch_result.csv`
FIRSTLINE=${FIRSTLINE%$'\r'}
if [ "$FIRSTLINE" != "_id,type,prefLabels,altLabels,hiddenLabels,geoLocation" ]; then
  FIRSTLINES=`head -20 ${TMPDIR}/fetch_result.csv`
  echo "Columns not found on first line of ${TMPDIR}/fetch_result.csv: '$FIRSTLINES'"
else
  mongoimport --quiet -d ${DATABASE} -c ${COLLECTION} --file ${TMPDIR}/fetch_result.csv --type=csv --headerline --mode=upsert
fi
