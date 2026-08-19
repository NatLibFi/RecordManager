<xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
  xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">
  <xsl:output method="xml" indent="no"/>

  <xsl:template name="wikidata-worktype">
    <xsl:param name="worktype"/>
    <xsl:param name="lang"/>

    <xsl:choose>

      <!--3D reconstruction-->
      <xsl:when test="($worktype='malli') or ($worktype='Malli')">
        <xsl:attribute name="rdf:resource"><xsl:value-of select="'http://www.wikidata.org/entity/Q4464732'"/></xsl:attribute>
      </xsl:when>
      <!--Applied arts-->
      <xsl:when test="($worktype='taideteollisuus') or ($worktype='taideteollisuusesine')">
        <xsl:attribute name="rdf:resource"><xsl:value-of select="'http://www.wikidata.org/entity/Q207241'"/></xsl:attribute>
      </xsl:when>
      <!--Archaeological artifact-->
      <xsl:when test="$worktype='maalöytö'">
        <xsl:attribute name="rdf:resource"><xsl:value-of select="'http://www.wikidata.org/entity/Q220659'"/></xsl:attribute>
      </xsl:when>
      <!--Archaeological site-->
      <xsl:when test="$worktype='arkeologinen kohde'">
        <xsl:attribute name="rdf:resource"><xsl:value-of select="'http://www.wikidata.org/entity/Q839954'"/></xsl:attribute>
      </xsl:when>
      <!-- Architecture -->
      <xsl:when test="$worktype='Architecture'">
        <xsl:attribute name="rdf:resource"><xsl:value-of select="'http://www.wikidata.org/entity/Q12271'"/></xsl:attribute>
      </xsl:when>
      <!--Archival resource-->
      <xsl:when test="($worktype='Archive') or ($worktype='archive material') or ($worktype='Arkisto') or ($worktype='arkisto') or ($worktype='Arkistoaineisto') or ($worktype='arkistoaineisto') or ($worktype='arkistomateriaali') or ($worktype='asiakirja') or ($worktype='dokumentti') or ($worktype='Dokumentti') or ($worktype='henkilöarkisto')">
        <xsl:attribute name="rdf:resource"><xsl:value-of select="'http://www.wikidata.org/entity/Q106815942'"/></xsl:attribute>
      </xsl:when>
      <!--Article-->
      <xsl:when test="$worktype='artikkeli'">
        <xsl:attribute name="rdf:resource"><xsl:value-of select="'http://www.wikidata.org/entity/Q191067'"/></xsl:attribute>
      </xsl:when>
      <!--Artificial physical object-->
      <xsl:when test="($worktype='artefact') or ($worktype='esine') or ($worktype='Esine') or ($worktype='item') or ($worktype='Item') or ($worktype='kulttuurihistoriallinen esine') or ($worktype='Object') or ($worktype='taiteilijan työvälineet') or ($worktype='työkalut')">
        <xsl:attribute name="rdf:resource"><xsl:value-of select="'http://www.wikidata.org/entity/Q8205328'"/></xsl:attribute>
      </xsl:when>
      <!--Assemblage-->
      <xsl:when test="($worktype='esineteos') or ($worktype='Esineteos')">
        <xsl:attribute name="rdf:resource"><xsl:value-of select="'http://www.wikidata.org/entity/Q262343'"/></xsl:attribute>
      </xsl:when>
      <!--Audio recording-->
      <xsl:when test="($worktype='Äänite') or ($worktype='äänite') or ($worktype='äänite, puhe') or ($worktype='Ääni')">
        <xsl:attribute name="rdf:resource"><xsl:value-of select="'http://www.wikidata.org/entity/Q262343'"/></xsl:attribute>
      </xsl:when>
      <!--Book-->
      <xsl:when test="($worktype='kirja') or ($worktype='Kirja')">
        <xsl:attribute name="rdf:resource"><xsl:value-of select="'http://www.wikidata.org/entity/Q571'"/></xsl:attribute>
      </xsl:when>
      <!--Building-->
      <xsl:when test="($worktype='Paikka, Rakennus') or ($worktype='rakennetun ympäristön kohde') or ($worktype='rakennus') or ($worktype='Rakennus')">
        <xsl:attribute name="rdf:resource"><xsl:value-of select="'http://www.wikidata.org/entity/Q41176'"/></xsl:attribute>
      </xsl:when>
      <!--Charcoal drawing-->
      <xsl:when test="$worktype='hiilipiirustus'">
        <xsl:attribute name="rdf:resource"><xsl:value-of select="'http://www.wikidata.org/entity/Q84080586'"/></xsl:attribute>
      </xsl:when>
      <!--Collage-->
      <xsl:when test="$worktype='paperikollaasi'">
        <xsl:attribute name="rdf:resource"><xsl:value-of select="'http://www.wikidata.org/entity/Q22669857'"/></xsl:attribute>
      </xsl:when>
      <!--Collection-->
      <xsl:when test="($worktype='Hankintaerä') or ($worktype='Hankintaerä (lapsi)')">
        <xsl:attribute name="rdf:resource"><xsl:value-of select="'https://www.wikidata.org/entity/Q2668072'"/></xsl:attribute>
      </xsl:when> 
      <!--Compact disc-->
      <xsl:when test="$worktype='CD'">
        <xsl:attribute name="rdf:resource"><xsl:value-of select="'http://www.wikidata.org/entity/Q34467'"/></xsl:attribute>
      </xsl:when>
      <!--Conceptual art-->
      <xsl:when test="$worktype='käsitetaide'">
        <xsl:attribute name="rdf:resource"><xsl:value-of select="'http://www.wikidata.org/entity/Q203209'"/></xsl:attribute>
      </xsl:when>
      <!--Diary-->
      <xsl:when test="$worktype='dagbok'">
        <xsl:attribute name="rdf:resource"><xsl:value-of select="'http://www.wikidata.org/entity/Q185598'"/></xsl:attribute>
      </xsl:when>
      <!--Digital record-->
      <xsl:when test="$worktype='atk-tallenne'">
        <xsl:attribute name="rdf:resource"><xsl:value-of select="'http://www.wikidata.org/entity/Q111664863'"/></xsl:attribute>
      </xsl:when>      
      <!--Drawing-->
      <xsl:when test="($worktype='piirustus') or ($worktype='Piirustus') or ($worktype='piirrustus') or ($worktype='Taideteos, piirustus') or ($worktype='Taideteos, piirros') or ($worktype='Taideteos/Piirros')or ($worktype='piirros') or ($worktype='Piirros') or ($worktype='piirustus/tussi') or ($worktype='piirustuskuva') or ($worktype='Piirustuskuva')">
        <xsl:attribute name="rdf:resource"><xsl:value-of select="'http://www.wikidata.org/entity/Q93184'"/></xsl:attribute>
      </xsl:when>
      <!--Environmental art-->
      <xsl:when test="($worktype='ympäristötaide') or ($worktype='ympäristöteos')">
        <xsl:attribute name="rdf:resource"><xsl:value-of select="'http://www.wikidata.org/entity/Q473743'"/></xsl:attribute>
      </xsl:when> 
      <!--Etching print-->
      <xsl:when test="$worktype='grafiikka/viivas.'">
        <xsl:attribute name="rdf:resource"><xsl:value-of select="'http://www.wikidata.org/entity/Q11060274'"/></xsl:attribute>
      </xsl:when>
      <!--Film-->
      <xsl:when test="$worktype='liikkuva kuva'">
        <xsl:attribute name="rdf:resource"><xsl:value-of select="'http://www.wikidata.org/entity/Q11424'"/></xsl:attribute>
      </xsl:when>
      <!--Fine-art photography-->
      <xsl:when test="($worktype='valokuvateos') or ($worktype='Valokuvateos')">
        <xsl:attribute name="rdf:resource"><xsl:value-of select="'http://www.wikidata.org/entity/Q1066582'"/></xsl:attribute>
      </xsl:when>
      <!--Folk music-->
      <xsl:when test="$worktype='folk music recording'">
        <xsl:attribute name="rdf:resource"><xsl:value-of select="'http://www.wikidata.org/entity/Q43343'"/></xsl:attribute>
      </xsl:when>
      <!--Gouache paint-->
      <xsl:when test="($worktype='maalaus/guassi')">
        <xsl:attribute name="rdf:resource"><xsl:value-of select="'https://www.wikidata.org/entity/Q204330'"/></xsl:attribute>
      </xsl:when>
      <!--Handbook-->
      <xsl:when test="($worktype='Tekninen Käsikirja')">
        <xsl:attribute name="rdf:resource"><xsl:value-of select="'https://www.wikidata.org/entity/Q1338914'"/></xsl:attribute>
      </xsl:when>
      <!--Handicraft-->
      <xsl:when test="($worktype='Hadicraft')">
        <xsl:attribute name="rdf:resource"><xsl:value-of select="'https://www.wikidata.org/entity/Q877729'"/></xsl:attribute>
      </xsl:when>
      <!--Illustration-->
      <xsl:when test="($worktype='kuvitus') or ($worktype='Kuvitus') or ($worktype='Kuvitusluonnos') or ($worktype='Kuvitus/maalaus') or ($worktype='Kuvitus/piirustus')">
        <xsl:attribute name="rdf:resource"><xsl:value-of select="'https://www.wikidata.org/entity/Q178659'"/></xsl:attribute>
      </xsl:when>
      <!--Installation artwork-->
      <xsl:when test="($worktype='installaatio') or ($worktype='Installaatio')">
        <xsl:attribute name="rdf:resource"><xsl:value-of select="'http://www.wikidata.org/entity/Q20437094'"/></xsl:attribute>
      </xsl:when>
      <!--Interview-->
      <xsl:when test="($worktype='haastattelu') or ($worktype='Haastattelu') or ($worktype='intervju')">
        <xsl:attribute name="rdf:resource"><xsl:value-of select="'http://www.wikidata.org/entity/Q178651'"/></xsl:attribute>
      </xsl:when>
      <!--Letter-->
      <xsl:when test="($worktype='kirje') or ($worktype='Kirje') or ($worktype='brev')">
        <xsl:attribute name="rdf:resource"><xsl:value-of select="'http://www.wikidata.org/entity/Q133492'"/></xsl:attribute>
      </xsl:when>
      <!--Linocut print-->
      <xsl:when test="$worktype='grafiikka/linop.'">
        <xsl:attribute name="rdf:resource"><xsl:value-of select="'http://www.wikidata.org/entity/Q22060043'"/></xsl:attribute>
      </xsl:when>
      <!--Lithography-->
      <xsl:when test="($worktype='kivipiirros') or ($worktype='Kivipiirros') or ($worktype='grafiikka/kivip.')">
        <xsl:attribute name="rdf:resource"><xsl:value-of select="'http://www.wikidata.org/entity/Q133036'"/></xsl:attribute>
      </xsl:when>
      <!--Manuscript-->
      <xsl:when test="($worktype='käsikirjoitus') or ($worktype='manuskript')">
        <xsl:attribute name="rdf:resource"><xsl:value-of select="'http://www.wikidata.org/entity/Q87167'"/></xsl:attribute>
      </xsl:when>
      <!--Map-->
      <xsl:when test="($worktype='kartta') or ($worktype='Kartta')">
        <xsl:attribute name="rdf:resource"><xsl:value-of select="'http://www.wikidata.org/entity/Q4006'"/></xsl:attribute>
      </xsl:when>
      <!--Minutes-->
      <xsl:when test="$worktype='Pöytäkirja'">
        <xsl:attribute name="rdf:resource"><xsl:value-of select="'http://www.wikidata.org/entity/Q2085515'"/></xsl:attribute>
      </xsl:when>
      <!--Mosaic-->
      <xsl:when test="$worktype='mosaiikki'">
        <xsl:attribute name="rdf:resource"><xsl:value-of select="'http://www.wikidata.org/entity/Q133067'"/></xsl:attribute>
      </xsl:when>
      <!--Museum object-->
      <xsl:when test="$worktype='Museum object'">
        <xsl:attribute name="rdf:resource"><xsl:value-of select="'http://www.wikidata.org/entity/Q18593264'"/></xsl:attribute>
      </xsl:when>
      <!--Music-->
      <xsl:when test="($worktype='Phonograph cylinder') or ($worktype='äänite, musiikki') or ($worktype='78rpm record')">
        <xsl:attribute name="rdf:resource"><xsl:value-of select="'http://www.wikidata.org/entity/Q638'"/></xsl:attribute>
      </xsl:when>
      <!--Musical notation-->
      <xsl:when test="($worktype='nuotti') or ($worktype='nuottijulkaisu') or ($worktype='nuottikäsikirjoitus')">
        <xsl:attribute name="rdf:resource"><xsl:value-of select="'http://www.wikidata.org/entity/Q233861'"/></xsl:attribute>
      </xsl:when>
      <!--Negative-->
      <xsl:when test="$worktype='negatiivi'">
        <xsl:attribute name="rdf:resource"><xsl:value-of select="'http://www.wikidata.org/entity/Q595597'"/></xsl:attribute>
      </xsl:when>
      <!--New media art-->
      <xsl:when test="($worktype='mediataide') or ($worktype='Mediataide') or ($worktype='monimediateos')">
        <xsl:attribute name="rdf:resource"><xsl:value-of select="'http://www.wikidata.org/entity/Q378604'"/></xsl:attribute>
      </xsl:when>
      <!--Oil painting-->
      <xsl:when test="$worktype='maalaus/öljy'">
        <xsl:attribute name="rdf:resource"><xsl:value-of select="'http://www.wikidata.org/entity/Q174705'"/></xsl:attribute>
      </xsl:when>
      <!--Painting-->
      <xsl:when test="($worktype='maalaus') or ($worktype='Maalaus') or ($worktype='Taideteos, maalaus') or ($worktype='maalaus/akryyli') or ($worktype='taulu') or ($worktype='Taulu') or ($worktype='Maalaus/Piirustus')">
        <xsl:attribute name="rdf:resource"><xsl:value-of select="'http://www.wikidata.org/entity/Q3305213'"/></xsl:attribute>
      </xsl:when>
      <!--Photograph-->
      <xsl:when test="($worktype='image') or ($worktype='kuva') or ($worktype='Kuva') or ($worktype='Kuva, Valokuva') or ($worktype='photograph') or ($worktype='Photo') or ($worktype='valoku') or ($worktype='valokuva') or ($worktype='Valokuva') or ($worktype='valokuvat')  or ($worktype='fotografi')">
        <xsl:attribute name="rdf:resource"><xsl:value-of select="'http://www.wikidata.org/entity/Q125191'"/></xsl:attribute>
      </xsl:when>
      <!--Photomechanical print-->
      <xsl:when test="$worktype='fotomekaaniset menetelmät'">
        <xsl:attribute name="rdf:resource"><xsl:value-of select="'http://www.wikidata.org/entity/Q100575647'"/></xsl:attribute>
      </xsl:when>
      <!--Postcard-->
      <xsl:when test="$worktype='postikortti'">
        <xsl:attribute name="rdf:resource"><xsl:value-of select="'http://www.wikidata.org/entity/Q192425'"/></xsl:attribute>
      </xsl:when>
      <!--Poster-->
      <xsl:when test="$worktype='affisch'">
        <xsl:attribute name="rdf:resource"><xsl:value-of select="'http://www.wikidata.org/entity/Q429785'"/></xsl:attribute>
      </xsl:when>
      <!--Print-->
      <xsl:when test="($worktype='grafiikka') or ($worktype='Grafiikka') or ($worktype='Taideteos, grafiikka') or ($worktype='Käyttögrafiikka') or ($worktype='grafiikka/pehmeäp.') or ($worktype='grafiikka/silkkip.') or ($worktype='painokuva')">
        <xsl:attribute name="rdf:resource"><xsl:value-of select="'http://www.wikidata.org/entity/Q11060274'"/></xsl:attribute>
      </xsl:when>
      <!--Printed matter-->
      <xsl:when test="($worktype='Julkaisut') or ($worktype='Kausijulkaisu') or ($worktype='lehti') or ($worktype='painettu tekstijulkaisu') or ($worktype='painotuote') or ($worktype='paperi') or ($worktype='Paperi') or ($worktype='pienpainate') or ($worktype='trycksak')">
        <xsl:attribute name="rdf:resource"><xsl:value-of select="'http://www.wikidata.org/entity/Q1261026'"/></xsl:attribute>
      </xsl:when>
      <!--Relief sculpture-->
      <xsl:when test="$worktype='Taideteos, reliefi, miniatyyri'">
        <xsl:attribute name="rdf:resource"><xsl:value-of select="'http://www.wikidata.org/entity/Q245117'"/></xsl:attribute>
      </xsl:when>
      <!--Sculpture-->
      <xsl:when test="($worktype='veistos') or ($worktype='Veistos') or ($worktype='veistos/kipsi') or ($worktype='veistos/pronssi') or ($worktype='veistos/teräs')">
        <xsl:attribute name="rdf:resource"><xsl:value-of select="'http://www.wikidata.org/entity/Q860861'"/></xsl:attribute>
      </xsl:when>
      <!--Sketch-->
      <xsl:when test="($worktype='luonnos') or ($worktype='Luonnos')">
        <xsl:attribute name="rdf:resource"><xsl:value-of select="'http://www.wikidata.org/entity/Q5078274'"/></xsl:attribute>
      </xsl:when>
      <!--Slide-->
      <xsl:when test="($worktype='dia') or ($worktype='diapositiivi')">
        <xsl:attribute name="rdf:resource"><xsl:value-of select="'http://www.wikidata.org/entity/Q3026325'"/></xsl:attribute>
      </xsl:when>
      <!--Technical drawing-->
      <xsl:when test="($worktype='Kuva, piirros') or ($worktype='kuva, piirros') or ($worktype='Kuva, piirustus') or ($worktype='kuva, piirustus') or ($worktype='kuva, työpiirustus') or ($worktype='Kuva, työpiirustus') or ($worktype='pääpiirustus') or ($worktype='Pääpiirustus')">
        <xsl:attribute name="rdf:resource"><xsl:value-of select="'http://www.wikidata.org/entity/Q192521'"/></xsl:attribute>
      </xsl:when>
      <!--Textile artwork-->
      <xsl:when test="($worktype='tekstiilitaide') or ($worktype='Tekstiilitaide') or ($worktype='tekstiiliteos') or ($worktype='Tekstiiliteos')">
        <xsl:attribute name="rdf:resource"><xsl:value-of select="'http://www.wikidata.org/entity/Q22075301'"/></xsl:attribute>
      </xsl:when>
      <!--User guide-->
      <xsl:when test="$worktype='Käyttöohje'">
        <xsl:attribute name="rdf:resource"><xsl:value-of select="'http://www.wikidata.org/entity/Q1057179'"/></xsl:attribute>
      </xsl:when>
      <!--Video recording-->
      <xsl:when test="($worktype='AV') or ($worktype='Video') or ($worktype='Audiovisual') or ($worktype='offentliggjord inspelning') or ($worktype='annan dokumentation') or ($worktype='offentliggjord produktion')">
        <xsl:attribute name="rdf:resource"><xsl:value-of select="'http://www.wikidata.org/entity/Q34508'"/></xsl:attribute>
      </xsl:when>
      <!--Vinyl record-->
      <xsl:when test="$worktype='Vinyl record'">
        <xsl:attribute name="rdf:resource"><xsl:value-of select="'http://www.wikidata.org/entity/Q178588'"/></xsl:attribute>
      </xsl:when>
      <!--Watercolor-->
      <xsl:when test="($worktype='Taideteos, akvarelli') or ($worktype='akvarelli') or ($worktype='Akvarelli')">
        <xsl:attribute name="rdf:resource"><xsl:value-of select="'http://www.wikidata.org/entity/Q50030'"/></xsl:attribute>
      </xsl:when>      
      <!--Woodcut print-->
      <xsl:when test="($worktype='puupiirros') or ($worktype='Puupiirros')">
        <xsl:attribute name="rdf:resource"><xsl:value-of select="'http://www.wikidata.org/entity/Q18219090'"/></xsl:attribute>
      </xsl:when>      
      <!--Work of art-->
      <xsl:when test="($worktype='taideteos') or ($worktype='Taideteos') or ($worktype='Artwork') or ($worktype='Work of art')">
        <xsl:attribute name="rdf:resource"><xsl:value-of select="'http://www.wikidata.org/entity/Q838948'"/></xsl:attribute>
      </xsl:when>
      <xsl:otherwise>
        <xsl:attribute name="xml:lang"><xsl:value-of select="$lang"></xsl:value-of></xsl:attribute>
        <xsl:value-of select="$worktype"/>
      </xsl:otherwise>

    </xsl:choose>

  </xsl:template>

</xsl:stylesheet>
