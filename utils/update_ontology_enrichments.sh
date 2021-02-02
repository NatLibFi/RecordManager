 #!/bin/bash -e

if [ "$3" == "" ]; then
  echo "Usage: $0 <SPARQL endpoint url> <Mongo database> <Mongo collection>"
  echo
  echo "Updates the ontology enrichment collection with data retrieved from a SPARQL server"
  echo
  echo "Example: $0 http://api.finto.fi/sparql recman ontologyEnrichment"
  echo
  exit
fi

DIR=$(dirname "$0")
URL=$1
DATABASE=$2
COLLECTION=$3
for TMPDIR in "$TMPDIR" "$TMP" /var/tmp /tmp
do
    test -d "$TMPDIR" && break
done

curl -s -H "Accept: text/csv" --data @${DIR}/fetch_yso.post ${URL} > ${TMPDIR}/yso.csv
FIRSTLINE=`head -1 ${TMPDIR}/yso.csv`
FIRSTLINE=${FIRSTLINE%$'\r'}
if [ "$FIRSTLINE" != "_id,type,prefLabels,altLabels" ]; then
  echo "Columns not found on first line of yso.csv: '$FIRSTLINE'"
else
  mongoimport --quiet -d ${DATABASE} -c ${COLLECTION} --file ${TMPDIR}/yso.csv --type=csv --headerline --mode=upsert
fi

curl -s -H "Accept: text/csv" --data @${DIR}/fetch_ysa.post ${URL} > ${TMPDIR}/ysa.csv
FIRSTLINE=`head -1 ${TMPDIR}/yso.csv`
FIRSTLINE=${FIRSTLINE%$'\r'}
if [ "$FIRSTLINE" != "_id,type,prefLabels,altLabels" ]; then
  echo "Columns not found on first line of yso.csv: '$FIRSTLINE'"
else
  mongoimport --quiet -d ${DATABASE} -c ${COLLECTION} --file ${TMPDIR}/ysa.csv --type=csv --headerline --mode=upsert
fi

curl -s -H "Accept: text/csv" --data @${DIR}/fetch_allars.post ${URL} > ${TMPDIR}/allars.csv
FIRSTLINE=`head -1 ${TMPDIR}/yso.csv`
FIRSTLINE=${FIRSTLINE%$'\r'}
if [ "$FIRSTLINE" != "_id,type,prefLabels,altLabels" ]; then
  echo "Columns not found on first line of yso.csv: '$FIRSTLINE'"
else
  mongoimport --quiet -d ${DATABASE} -c ${COLLECTION} --file ${TMPDIR}/allars.csv --type=csv --headerline --mode=upsert
fi
