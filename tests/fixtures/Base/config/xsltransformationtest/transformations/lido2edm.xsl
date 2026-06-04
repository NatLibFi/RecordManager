<?xml version="1.0"?> 
<xsl:stylesheet version="1.0" 
  xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
  xmlns:dc="http://purl.org/dc/elements/1.1/" 
  xmlns:dcterms="http://purl.org/dc/terms/" 
  xmlns:edm="http://www.europeana.eu/schemas/edm/" 
  xmlns:ore="http://www.openarchives.org/ore/terms/"
  xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
  xmlns:lido="http://www.lido-schema.org"
  xmlns:ns5="http://www.openarchives.org/OAI/2.0/"
  xmlns:gml="http://www.opengis.net/gml"
  xmlns:skos="http://www.w3.org/2004/02/skos/core#"
  xmlns:wgs84_pos="http://www.w3.org/2003/01/geo/wgs84_pos#"
  xmlns:svcs="http://rdfs.org/sioc/services#"
  xmlns:rdfs="http://www.w3.org/2000/01/rdf-schema#"
  xmlns:php="http://php.net/xsl">

  <xsl:import href="europeana-license.xsl"/>
  <xsl:import href="url-encode.xsl"/>
  <xsl:import href="validate-lang.xsl"/>
  <xsl:import href="validate-text-lang.xsl"/>
  <xsl:import href="wikidata-worktype.xsl"/>
  <xsl:output method="xml" encoding="UTF-8" indent="yes" /> 

  <!-- To use this template, properties file must include following info: -->
    <!-- Parameters: $museum, $provider, $data_provider, $default_type, $sourceURL  -->
    <!-- PHP functions: str_replace, rawurlencode, mb_strtolower  -->
  
  <!-- Unique identifier -->
  <xsl:variable name="recordID" select="//lido:lidoRecID"/>

  <!-- The resource type handled as the main resource -->
  <xsl:variable name="mainResourceType">
    <xsl:choose>
      <xsl:when test="//lido:resourceRepresentation[@lido:type='provided_3D']/lido:linkResource[normalize-space(.)!='']">
        <xsl:value-of select="'provided_3D'"/>
      </xsl:when>
      <xsl:when test="//lido:resourceRepresentation[@lido:type='preview_3D']/lido:linkResource[normalize-space(.)!='']">
        <xsl:value-of select="'preview_3D'"/>
      </xsl:when>
      <xsl:when test="//lido:resourceRepresentation[@lido:type='preview_video']/lido:linkResource[normalize-space(.)!='']">
        <xsl:value-of select="'preview_video'"/>
      </xsl:when>
      <xsl:when test="//lido:resourceRepresentation[@lido:type='provided_video']/lido:linkResource[normalize-space(.)!='']">
        <xsl:value-of select="'provided_video'"/>
      </xsl:when>
      <xsl:when test="//lido:resourceRepresentation[@lido:type='preview_sound']/lido:linkResource[normalize-space(.)!='']">
        <xsl:value-of select="'preview_sound'"/>
      </xsl:when>
      <xsl:when test="//lido:resourceRepresentation[@lido:type='provided_sound']/lido:linkResource[normalize-space(.)!='']">
        <xsl:value-of select="'provided_sound'"/>
      </xsl:when>
      <xsl:when test="//lido:resourceRepresentation[@lido:type='image_master']/lido:linkResource[normalize-space(.)!='']">
        <xsl:value-of select="'image_master'"/>
      </xsl:when>
      <xsl:when test="//lido:resourceRepresentation[@lido:type='image_large']/lido:linkResource[normalize-space(.)!='']">
        <xsl:value-of select="'image_large'"/>
      </xsl:when>
      <xsl:when test="//lido:resourceRepresentation[@lido:type='large']/lido:linkResource[normalize-space(.)!='']">
        <xsl:value-of select="'large'"/>
      </xsl:when>
      <xsl:when test="//lido:resourceRepresentation[not(@lido:type)]/lido:linkResource[normalize-space(.)!='']">
        <xsl:value-of select="'no_type'"/>
      </xsl:when>
      <xsl:when test="//lido:resourceRepresentation[@lido:type='preview_text']/lido:linkResource[(normalize-space(.)!='') and (@lido:formatResource!='docx')]">
        <xsl:value-of select="'preview_text'"/>
      </xsl:when>
      <xsl:when test="//lido:resourceRepresentation[@lido:type='provided_text']/lido:linkResource[(normalize-space(.)!='') and (@lido:formatResource!='docx')]">
        <xsl:value-of select="'provided_text'"/>
      </xsl:when>
      <xsl:when test="//lido:resourceRepresentation[@lido:type='image_original']/lido:linkResource[normalize-space(.)!='']">
        <xsl:value-of select="'image_original'"/>
      </xsl:when>
      <xsl:when test="//lido:resourceRepresentation[@lido:type='image_thumb']/lido:linkResource[normalize-space(.)!='']">
        <xsl:value-of select="'image_thumb'"/>
      </xsl:when>
      <xsl:otherwise>
        <!-- Should not happen -->
        <xsl:value-of select="'none'"/>
      </xsl:otherwise>
    </xsl:choose>
  </xsl:variable>

  <!-- URL of the main resource -->
  <xsl:variable name="isShownByLink">
    <xsl:choose>
      <xsl:when test="$mainResourceType = 'no_type'">
        <xsl:value-of select="//lido:administrativeMetadata/lido:resourceWrap/lido:resourceSet/lido:resourceRepresentation[not(@lido:type)]/lido:linkResource[normalize-space(.)!='']"/>
      </xsl:when>
      <xsl:when test="$mainResourceType = 'none'">
        <xsl:value-of select="'none'"/>
      </xsl:when>
      <xsl:otherwise>
        <xsl:value-of select="//lido:administrativeMetadata/lido:resourceWrap/lido:resourceSet/lido:resourceRepresentation[@lido:type=$mainResourceType]/lido:linkResource[normalize-space(.)!='']"/>
      </xsl:otherwise>
    </xsl:choose>
  </xsl:variable>
    
  <xsl:template match="/"> 
    <rdf:RDF> 
      <xsl:apply-templates select="//lido:lido" /> 
    </rdf:RDF> 
  </xsl:template> 

  <xsl:template match="//lido:lido"> 
    
    <!-- Cultural Heritage Object --> 
    
    <edm:ProvidedCHO>

      <!-- Unique identifier. Local identifiers should start with #. -->
      <xsl:attribute name="rdf:about">
        <xsl:value-of select="concat('#', $recordID)"/> 
      </xsl:attribute>

      <!-- dc:creator -->      
      <xsl:for-each select="//lido:eventWrap/lido:eventSet/lido:event/lido:eventActor/lido:actorInRole/lido:actor/lido:nameActorSet/lido:appellationValue[normalize-space(.)!='']"> 
        <xsl:if test="(../../../../../../lido:event/lido:eventType/lido:term='Valmistus') or (../../../../../../lido:event/lido:eventType/lido:term='valmistus')">
          <dc:creator>
            <xsl:value-of select="normalize-space(.)"/> 
          </dc:creator> 
        </xsl:if>
      </xsl:for-each>
      
      <!-- dc:description, objectDescriptionSet -->
      <xsl:for-each select="//lido:objectDescriptionWrap/lido:objectDescriptionSet/lido:descriptiveNoteValue[normalize-space(.)!='']"> 
          <dc:description>
            <xsl:attribute name="xml:lang">
              <xsl:call-template name="validatelang">
                <xsl:with-param name="lang" select="@xml:lang"/>
              </xsl:call-template>
            </xsl:attribute>
            <xsl:value-of select="normalize-space(.)"/> 
          </dc:description> 
      </xsl:for-each>

      <!-- dc:description, eventDescriptionSet -->
      <xsl:for-each select="//lido:eventWrap/lido:eventSet/lido:event/lido:eventDescriptionSet/lido:descriptiveNoteValue[normalize-space(.)!='']">
        <dc:description>
          <xsl:attribute name="xml:lang">
            <xsl:call-template name="validatelang">
              <xsl:with-param name="lang" select="@xml:lang"/>
            </xsl:call-template>
          </xsl:attribute>
          <xsl:value-of select="normalize-space(.)"/>
      </dc:description>
      </xsl:for-each>

      <!-- dc:description, eventMethod -->
      <xsl:for-each select="//lido:eventWrap/lido:eventSet/lido:event/lido:eventMethod/lido:term[normalize-space(.)!='']">
        <dc:description>
          <xsl:attribute name="xml:lang">
            <xsl:call-template name="validatelang">
              <xsl:with-param name="lang" select="@xml:lang"/>
            </xsl:call-template>
          </xsl:attribute>
          <xsl:value-of select="normalize-space(.)"/>         
        </dc:description>
      </xsl:for-each>

      <!-- dc:identifier -->
      <xsl:for-each select="//lido:repositoryWrap/lido:repositorySet/lido:workID[normalize-space(.)!='']"> 
        <dc:identifier>
          <xsl:value-of select="normalize-space(.)" /> 
        </dc:identifier> 
      </xsl:for-each> 

      <!-- dc:language -->
      <xsl:choose>
        <xsl:when test="//lido:classification[@lido:type='language']/lido:term[normalize-space(.)!='']">
          <dc:language>
            <xsl:call-template name="validatetextlang">
              <xsl:with-param name="lang" select="//lido:classification[@lido:type='language']/lido:term[normalize-space(.)!='']"/>
            </xsl:call-template>
          </dc:language>
        </xsl:when>
        <xsl:otherwise>
          <!-- Add mandatory language field for textual materials -->
          <xsl:if test="//lido:resourceRepresentation[(@lido:type='provided_text') or (@lido:type='preview_text')]/lido:linkResource[normalize-space(.)!='']">
            <dc:language>
              <xsl:value-of select="'fin'"/>
            </dc:language>
          </xsl:if>
        </xsl:otherwise>
      </xsl:choose>
      
      <!-- dc:subject, subject terms -->
      <xsl:for-each select="//lido:objectRelationWrap/lido:subjectWrap/lido:subjectSet/lido:subject/lido:subjectConcept">
        <xsl:choose>
          <!-- Links to supported LOD vocabularies -->
          <xsl:when test="./lido:conceptID[starts-with(., 'http://www.yso.fi/onto/yso/')]">
            <dc:subject>
              <xsl:attribute name="rdf:resource">
                <xsl:value-of select="./lido:conceptID[starts-with(., 'http://www.yso.fi/onto/yso/')]"/>
              </xsl:attribute>
            </dc:subject>
          </xsl:when>
          <xsl:when test="./lido:conceptID[starts-with(., 'https://iconclass.org/')]">
            <dc:subject>
              <xsl:attribute name="rdf:resource">
                <xsl:value-of select="./lido:conceptID[starts-with(., 'https://iconclass.org/')]"/>
              </xsl:attribute>
            </dc:subject>
          </xsl:when>
          <xsl:when test="./lido:conceptID[starts-with(., 'http://iconclass.org/')]">
            <dc:subject>
                <xsl:attribute name="rdf:resource">
                    <xsl:value-of select="'https://iconclass.org/'"/>
                    <xsl:value-of select="substring-after(./lido:conceptID[starts-with(., 'http://iconclass.org/')], '.org/')"/>
                </xsl:attribute>
            </dc:subject>
          </xsl:when>
          <xsl:otherwise>
            <!-- Add terms only if there is no supported URI. -->
            <xsl:for-each select="./lido:term[normalize-space(.)!='']">
              <dc:subject>
                <xsl:attribute name="xml:lang">
                  <xsl:call-template name="validatelang">
                    <xsl:with-param name="lang" select="@xml:lang"/>
                  </xsl:call-template>
                </xsl:attribute>
                <xsl:value-of select="normalize-space(.)"/>
              </dc:subject>
            </xsl:for-each>
          </xsl:otherwise>
        </xsl:choose>
      </xsl:for-each>

      <!-- dc:subject, subject actors -->
      <xsl:for-each select="//lido:objectRelationWrap/lido:subjectWrap/lido:subjectSet/lido:subject/lido:subjectActor/lido:actor/lido:nameActorSet/lido:appellationValue[normalize-space(.)!='']">
        <dc:subject>
          <xsl:value-of select="normalize-space(.)"/>
        </dc:subject>
      </xsl:for-each>
      
      <!-- dc:title --> 
      <xsl:for-each select="//lido:titleWrap/lido:titleSet/lido:appellationValue[normalize-space(.)!='']">
        <dc:title>
          <xsl:attribute name="xml:lang">
            <xsl:call-template name="validatelang">
              <xsl:with-param name="lang" select="@xml:lang"/>
            </xsl:call-template>
          </xsl:attribute>
          <xsl:value-of select="normalize-space(.)"/>
        </dc:title>
      </xsl:for-each> 
      <xsl:if test="not(//lido:titleWrap/lido:titleSet/lido:appellationValue[normalize-space(.)!=''])">
        <!-- Add mandatory title -->
        <dc:title>
          <xsl:attribute name="xml:lang">
            <xsl:value-of select="'fi'"/>
          </xsl:attribute>
            <xsl:value-of select="'Ei otsikkoa'"/>
        </dc:title>
      </xsl:if>

      <!-- dc:type, objectWorkType -->
      <xsl:for-each select="//lido:objectWorkTypeWrap/lido:objectWorkType/lido:term[normalize-space(.)!='']">
        <dc:type>
          <xsl:call-template name="wikidata-worktype">
            <xsl:with-param name="worktype" select="normalize-space(.)"/>
            <xsl:with-param name="lang" select="@xml:lang"/>
          </xsl:call-template>
        </dc:type>
      </xsl:for-each>

      <!-- dc:type, classifications -->
      <xsl:for-each select="//lido:objectClassificationWrap/lido:classificationWrap/lido:classification[(@lido:type!='language') or not(@lido:type)]/lido:term[normalize-space(.)!='']">
        <!-- Do not include classifications containing only numbers. -->
        <xsl:if test="normalize-space(translate(., '0123456789', '         '))!=''">
          <dc:type>
            <xsl:attribute name="xml:lang">
              <xsl:call-template name="validatelang">
                <xsl:with-param name="lang" select="@xml:lang"/>
              </xsl:call-template>
            </xsl:attribute>
            <xsl:value-of select="normalize-space(.)"/>
          </dc:type>
        </xsl:if>
      </xsl:for-each>

      <!-- Add mandatory dc:type -->
      <xsl:if test="not(//lido:objectWorkTypeWrap/lido:objectWorkType/lido:term[normalize-space(.)!='']) and not(//lido:classificationWrap/lido:classification[@lido:type!='language']/lido:term[normalize-space(.)!=''])">
        <dc:type>
          <xsl:call-template name="wikidata-worktype">
            <xsl:with-param name="worktype" select="$default_type"/>
            <xsl:with-param name="lang" select="'fi'"/>
          </xsl:call-template>
        </dc:type>
      </xsl:if>
     
      <!-- dcterms:created, date from creation event --> 
      <xsl:for-each select="//lido:eventWrap/lido:eventSet/lido:event/lido:eventDate/lido:displayDate[normalize-space(.)!='']">
        <xsl:if test="(../../../lido:event/lido:eventType/lido:term='Valmistus') or (../../../lido:event/lido:eventType/lido:term='valmistus')">
          <dcterms:created>
            <xsl:attribute name="xml:lang">
              <xsl:call-template name="validatelang">
                <xsl:with-param name="lang" select="@xml:lang"/>
              </xsl:call-template>
            </xsl:attribute>
            <xsl:value-of select="normalize-space(.)"/>
          </dcterms:created> 
        </xsl:if>
      </xsl:for-each>

      <!-- dcterms:extent -->
      <xsl:for-each select="//lido:objectMeasurementsWrap/lido:objectMeasurementsSet/lido:displayObjectMeasurements[normalize-space(.)!='']"> 
        <dcterms:extent>
          <xsl:attribute name="xml:lang">
            <xsl:call-template name="validatelang">
              <xsl:with-param name="lang" select="@xml:lang"/>
            </xsl:call-template>
          </xsl:attribute>
          <xsl:value-of select="normalize-space(.)"/>
        </dcterms:extent>  
      </xsl:for-each>
      <xsl:if test="not(//lido:objectMeasurementsWrap/lido:objectMeasurementsSet/lido:displayObjectMeasurements[normalize-space(.)!=''])">
        <xsl:for-each select="//lido:objectMeasurementsWrap/lido:objectMeasurementsSet/lido:objectMeasurements">
          <xsl:for-each select="./lido:measurementsSet[./lido:measurementValue!='']">
            <dcterms:extent>
              <xsl:if test="./lido:measurementType[normalize-space(.)!='']">
                <xsl:value-of select="./lido:measurementType[normalize-space(.)!='']"/>
                <xsl:value-of select="': '"/>
              </xsl:if>
              <xsl:if test="./lido:measurementValue[normalize-space(.)!='']">
                <xsl:value-of select="./lido:measurementValue[normalize-space(.)!='']"/>
                <xsl:value-of select="' '"/>
              </xsl:if>
              <xsl:if test="./lido:measurementUnit[normalize-space(.)!='']">
                <xsl:value-of select="./lido:measurementUnit[normalize-space(.)!='']"/>
              </xsl:if>
            </dcterms:extent>  
          </xsl:for-each>
        </xsl:for-each>
      </xsl:if>    

      <!-- dcterms:isPartOf, collections -->
      <xsl:for-each select="//lido:objectRelationWrap/lido:relatedWorksWrap/lido:relatedWorkSet/lido:relatedWork/lido:displayObject[normalize-space(.)!='']">
        <xsl:if test="(../../lido:relatedWorkRelType/lido:term='kokoelma') or (../../lido:relatedWorkRelType/lido:term='Kokoelma') or (../../lido:relatedWorkRelType/lido:term='kuuluu kokoelmaan') or (../../lido:relatedWorkRelType/lido:term='arkisto') or (../../lido:relatedWorkRelType/lido:term='Arkisto') or (../../lido:relatedWorkRelType/lido:term='alakokoelma') or (../../lido:relatedWorkRelType/lido:term='Alakokoelma') or (../../lido:relatedWorkRelType/lido:term='erityiskokoelma') or (../../lido:relatedWorkRelType/lido:term='Erityiskokoelma')">
          <dcterms:isPartOf>
            <xsl:attribute name="xml:lang">
              <xsl:call-template name="validatelang">
                <xsl:with-param name="lang" select="@xml:lang"/>
              </xsl:call-template>
            </xsl:attribute>
            <xsl:value-of select="normalize-space(.)"/>
          </dcterms:isPartOf>
        </xsl:if>
      </xsl:for-each>

      <!-- dcterms:isPartOf, exhibition, needed for Pagode materials -->
      <xsl:for-each select="//lido:eventWrap/lido:eventSet/lido:event/lido:eventName/lido:appellationValue[normalize-space(.)!='']">
        <xsl:if test="(../../../lido:event/lido:eventType/lido:term='Näyttely') or (../../../lido:event/lido:eventType/lido:term='näyttely')">
          <dcterms:isPartOf>
            <xsl:attribute name="xml:lang">
              <xsl:call-template name="validatelang">
                <xsl:with-param name="lang" select="@xml:lang"/>
              </xsl:call-template>
            </xsl:attribute>
            <xsl:value-of select="normalize-space(.)"/>
          </dcterms:isPartOf>
        </xsl:if>
      </xsl:for-each>

      <!-- dcterms:medium -->
       <xsl:for-each select="//lido:eventWrap/lido:eventSet/lido:event/lido:eventMaterialsTech">
        <xsl:for-each select="./lido:displayMaterialsTech[normalize-space(.)!='']">
          <dcterms:medium>
            <xsl:attribute name="xml:lang">
              <xsl:call-template name="validatelang">
                <xsl:with-param name="lang" select="@xml:lang"/>
              </xsl:call-template>
            </xsl:attribute>
            <xsl:value-of select="normalize-space(.)"/>
          </dcterms:medium>
        </xsl:for-each>
        <xsl:if test="not(./lido:displayMaterialsTech[normalize-space(.)!=''])">
          <xsl:for-each select="./lido:materialsTech/lido:termMaterialsTech/lido:term[normalize-space(.)!='']">
            <dcterms:medium>
              <xsl:attribute name="xml:lang">
                <xsl:call-template name="validatelang">
                  <xsl:with-param name="lang" select="@xml:lang"/>
                </xsl:call-template>
              </xsl:attribute>
              <xsl:value-of select="normalize-space(.)"/>
            </dcterms:medium>
          </xsl:for-each>
        </xsl:if>
      </xsl:for-each>

      <!-- dcterms:spatial, eventPlace -->
      <xsl:for-each select="//lido:eventWrap/lido:eventSet/lido:event/lido:eventPlace/lido:displayPlace[normalize-space(.)!='']">
        <dcterms:spatial>
          <xsl:attribute name="xml:lang">
            <xsl:call-template name="validatelang">
              <xsl:with-param name="lang" select="@xml:lang"/>
            </xsl:call-template>
          </xsl:attribute>
          <xsl:value-of select="normalize-space(.)"/> 
        </dcterms:spatial> 
      </xsl:for-each>

      <!-- dcterms:spatial, event places with coordinates: create a Place class -->
      <xsl:for-each select="//lido:eventWrap/lido:eventSet/lido:event/lido:eventPlace">
          <xsl:if test="(normalize-space(./lido:place/lido:namePlaceSet/lido:appellationValue)!='') and (normalize-space(./lido:place/lido:gml/gml:Point/gml:pos)!='')">
            <dcterms:spatial>
              <xsl:attribute name="rdf:resource"> 
                <xsl:value-of select="concat('#', concat(concat($recordID, '_place_'), position()))"/>
              </xsl:attribute>
            </dcterms:spatial>
          </xsl:if>
      </xsl:for-each>

      <!-- dcterms:spatial, subjectPlace -->
      <xsl:for-each select="//lido:subjectWrap/lido:subjectSet/lido:subject/lido:subjectPlace/lido:displayPlace[normalize-space(.)!='']">
        <dcterms:spatial>
          <xsl:attribute name="xml:lang">
            <xsl:call-template name="validatelang">
              <xsl:with-param name="lang" select="@xml:lang"/>
            </xsl:call-template>
          </xsl:attribute>
          <xsl:value-of select="normalize-space(.)"/>
        </dcterms:spatial>
      </xsl:for-each>

      <!-- dcterms:temporal, other events than creation -->
      <xsl:for-each select="//lido:eventWrap/lido:eventSet/lido:event/lido:eventDate/lido:displayDate[normalize-space(.)!='']">
        <xsl:if test="not(../../lido:eventType/lido:term[.='Valmistus']) and not(../../lido:eventType/lido:term[.='valmistus'])">
          <dcterms:temporal>
            <xsl:attribute name="xml:lang">
              <xsl:call-template name="validatelang">
                <xsl:with-param name="lang" select="@xml:lang"/>
              </xsl:call-template>
            </xsl:attribute>
            <xsl:value-of select="normalize-space(.)"/>
          </dcterms:temporal>
        </xsl:if>
      </xsl:for-each>

      <!-- dcterms:temporal, subject date -->
      <xsl:for-each select="//lido:subjectWrap/lido:subjectSet/lido:subject/lido:subjectDate/lido:displayDate[normalize-space(.)!='']">
        <dcterms:temporal>
          <xsl:attribute name="xml:lang">
            <xsl:call-template name="validatelang">
              <xsl:with-param name="lang" select="@xml:lang"/>
            </xsl:call-template>
          </xsl:attribute>
          <xsl:value-of select="normalize-space(.)"/>
        </dcterms:temporal>
      </xsl:for-each>

      <!-- edm:type, mandatory, choose from 3D, VIDEO, IMAGE, TEXT, SOUND -->
      <xsl:choose>
        <xsl:when test="($mainResourceType = 'preview_3D') or ($mainResourceType = 'provided_3D')">
          <edm:type>3D</edm:type>
        </xsl:when>
        <xsl:when test="($mainResourceType = 'preview_video') or ($mainResourceType = 'provided_video')">
          <edm:type>VIDEO</edm:type>
        </xsl:when>
        <xsl:when test="($mainResourceType = 'preview_audio') or ($mainResourceType = 'provided_audio')">
          <edm:type>SOUND</edm:type>
        </xsl:when>
        <xsl:when test="($mainResourceType = 'image_master') or ($mainResourceType = 'image_large')">
          <edm:type>IMAGE</edm:type>
        </xsl:when>
        <xsl:when test="($mainResourceType = 'preview_text') or ($mainResourceType = 'provided_text')">
          <edm:type>TEXT</edm:type>
        </xsl:when>
        <xsl:otherwise>
          <edm:type>IMAGE</edm:type>
        </xsl:otherwise>
      </xsl:choose>

    </edm:ProvidedCHO> 

    <!-- WebResource --> 

    <xsl:for-each select="//lido:administrativeMetadata/lido:resourceWrap/lido:resourceSet">
      <xsl:variable name="resourceLic" select="./lido:rightsResource/lido:rightsType/lido:conceptID[(@lido:type='Copyright') or (@lido:type='copyright')]"/>
      <!-- Add every first 3D, video, sound and text resource -->
      <!-- Link to 3D viewer, provided_3D -->
      <xsl:if test="./lido:resourceRepresentation[@lido:type='provided_3D']/lido:linkResource[normalize-space(.)!='']">
        <xsl:variable name="viewerLink" select="./lido:resourceRepresentation[@lido:type='provided_3D']/lido:linkResource[normalize-space(.)!='']"/>
        <edm:WebResource>
          <xsl:attribute name="rdf:about">
            <!-- Transform URL to required syntax -->
            <xsl:choose>
              <!-- Links to Sketchfab -->
              <xsl:when test="starts-with($viewerLink, 'https://sketchfab.com/3d-models')">
                <xsl:value-of select="'https://sketchfab.com/oembed?url='"/>
                <xsl:value-of select="php:function('rawurlencode',string($viewerLink))"/>
                <xsl:value-of select="'&amp;format=json'"/>
              </xsl:when>
              <!-- Other links -->
              <xsl:otherwise>
                <xsl:call-template name="urlencode">
                  <xsl:with-param name="url" select="$viewerLink"/>
                </xsl:call-template>
              </xsl:otherwise>
            </xsl:choose>
          </xsl:attribute>
          <!-- Creator of the resource -->
          <xsl:if test="normalize-space(./lido:rightsResource/lido:creditLine) != ''">
            <dc:creator>
              <xsl:value-of select="normalize-space(./lido:rightsResource/lido:creditLine)"/>
            </dc:creator>
          </xsl:if>
          <!-- Rights holder -->
          <xsl:if test="normalize-space(./lido:rightsResource/lido:rightsHolder/lido:legalBodyName/lido:appellationValue) != ''">
            <dc:rights>
              <xsl:value-of select="normalize-space(./lido:rightsResource/lido:rightsHolder/lido:legalBodyName/lido:appellationValue)"/>
            </dc:rights>
          </xsl:if>
          <!-- Mandatory field for 3D: 3D model's intended usage, check possible values: https://data.europeana.eu/vocabulary/usageArea/ -->
          <!-- Currently all materials are in Education category. -->
          <edm:intendedUsage>
            <xsl:attribute name="rdf:resource">      
              <xsl:value-of select="'http://data.europeana.eu/vocabulary/usageArea/Education'"/>
            </xsl:attribute>
          </edm:intendedUsage>
          <!-- Mandatory field: Resource rights -->
          <edm:rights>
            <xsl:attribute name="rdf:resource">
              <xsl:call-template name="europeanalic">
                <xsl:with-param name="license" select="$resourceLic"/>
              </xsl:call-template>
            </xsl:attribute>
          </edm:rights>
          <!-- Mandatory field for 3D viewer: Viewer service-->
          <xsl:if test="contains(./lido:resourceRepresentation[@lido:type='provided_3D']/lido:linkResource, 'sketchfab')">
            <svcs:has_service>
              <xsl:attribute name="rdf:resource">      
                <xsl:value-of select="'https://sketchfab.com/oembed'"/>
              </xsl:attribute>
            </svcs:has_service>
          </xsl:if>
        </edm:WebResource> 
      </xsl:if>

      <!-- Link to 3D file, preview_3D -->
      <xsl:if test="./lido:resourceRepresentation[@lido:type='preview_3D']/lido:linkResource[normalize-space(.)!='']">
        <edm:WebResource>
          <xsl:attribute name="rdf:about">      
            <xsl:call-template name="urlencode">
              <xsl:with-param name="url" select="./lido:resourceRepresentation[@lido:type='preview_3D']/lido:linkResource[normalize-space(.)!='']"/>
            </xsl:call-template>
          </xsl:attribute>
          <!-- Creator of the resource -->
          <xsl:if test="normalize-space(./lido:rightsResource/lido:creditLine) != ''">
            <dc:creator>
              <xsl:value-of select="normalize-space(./lido:rightsResource/lido:creditLine)"/>
            </dc:creator>
          </xsl:if>
          <!-- Rights holder -->
          <xsl:if test="normalize-space(./lido:rightsResource/lido:rightsHolder/lido:legalBodyName/lido:appellationValue) != ''">
            <dc:rights>
              <xsl:value-of select="normalize-space(./lido:rightsResource/lido:rightsHolder/lido:legalBodyName/lido:appellationValue)"/>
            </dc:rights>
          </xsl:if>
          <!-- Mandatory field for 3D files: Model type, check possible values: https://data.europeana.eu/vocabulary/modelType/ -->
          <xsl:if test="normalize-space(./lido:resourceType/lido:term) = '3D Mesh'">
            <dc:type>
              <xsl:attribute name="rdf:resource">      
                <xsl:value-of select="'http://data.europeana.eu/vocabulary/modelType/3DMesh'"/>
              </xsl:attribute>
            </dc:type>
          </xsl:if>
          <!-- Mandatory field for 3D: 3D model's intended usage, check possible values: https://data.europeana.eu/vocabulary/usageArea/ -->
          <!-- Currently all materials are in Education category. -->
          <edm:intendedUsage>
            <xsl:attribute name="rdf:resource">      
              <xsl:value-of select="'http://data.europeana.eu/vocabulary/usageArea/Education'"/>
            </xsl:attribute>
          </edm:intendedUsage>
          <!-- Mandatory field for 3D files: Polygon count -->
          <xsl:choose>
            <xsl:when test="./lido:resourceRepresentation[@lido:type='preview_3D']/lido:resourceMeasurementsSet[./lido:measurementUnit = 'polygons']">
              <edm:polygonCount>
                <xsl:value-of select="translate(./lido:resourceRepresentation[@lido:type='preview_3D']/lido:resourceMeasurementsSet[./lido:measurementUnit = 'polygons']/lido:measurementValue,' ','')"/>
              </edm:polygonCount>
            </xsl:when>
            <xsl:when test="./lido:resourceRepresentation[@lido:type='preview_3D']/lido:resourceMeasurementsSet[./lido:measurementUnit = 'triangles']">
              <edm:polygonCount>
                <xsl:value-of select="translate(./lido:resourceRepresentation[@lido:type='preview_3D']/lido:resourceMeasurementsSet[./lido:measurementUnit = 'triangles']/lido:measurementValue,' ','')"/>
              </edm:polygonCount>
            </xsl:when>
            <xsl:otherwise>
              <xsl:if test="./lido:resourceRepresentation[@lido:type='preview_3D']/lido:resourceMeasurementsSet[./lido:measurementUnit = 'faces']">
                <edm:polygonCount>
                  <xsl:value-of select="translate(./lido:resourceRepresentation[@lido:type='preview_3D']/lido:resourceMeasurementsSet[./lido:measurementUnit = 'faces']/lido:measurementValue,' ','')"/>
                </edm:polygonCount>
              </xsl:if>
            </xsl:otherwise>
          </xsl:choose>
          <!-- Mandatory field: Resource rights -->
          <edm:rights>
            <xsl:attribute name="rdf:resource">
              <xsl:call-template name="europeanalic">
                <xsl:with-param name="license" select="$resourceLic"/>
              </xsl:call-template>
            </xsl:attribute>
          </edm:rights>
          <!-- Mandatory field for 3D files: Vertex count -->
          <xsl:if test="./lido:resourceRepresentation[@lido:type='preview_3D']/lido:resourceMeasurementsSet[./lido:measurementUnit = 'vertices']">
            <edm:vertexCount>
              <xsl:value-of select="translate(./lido:resourceRepresentation[@lido:type='preview_3D']/lido:resourceMeasurementsSet[./lido:measurementUnit = 'vertices']/lido:measurementValue,' ','')"/>
            </edm:vertexCount>
          </xsl:if>
        </edm:WebResource> 
      </xsl:if>
  
      <!-- Viewable videos, preview_video -->
      <xsl:if test="./lido:resourceRepresentation[@lido:type='preview_video']/lido:linkResource[normalize-space(.)!='']">
        <edm:WebResource>
          <xsl:attribute name="rdf:about">      
            <xsl:call-template name="urlencode">
              <xsl:with-param name="url" select="./lido:resourceRepresentation[@lido:type='preview_video']/lido:linkResource[normalize-space(.)!='']"/>
            </xsl:call-template>
          </xsl:attribute>
          <edm:rights>
            <xsl:attribute name="rdf:resource">
              <xsl:call-template name="europeanalic">
                <xsl:with-param name="license" select="$resourceLic"/>
              </xsl:call-template>
            </xsl:attribute>
          </edm:rights>
          <xsl:if test="normalize-space(./lido:rightsResource/lido:creditLine) != ''">
            <dc:creator>
              <xsl:value-of select="normalize-space(./lido:rightsResource/lido:creditLine)"/>
            </dc:creator>
          </xsl:if>
          <xsl:if test="normalize-space(./lido:rightsResource/lido:rightsHolder/lido:legalBodyName/lido:appellationValue) != ''">
            <dc:rights>
              <xsl:value-of select="normalize-space(./lido:rightsResource/lido:rightsHolder/lido:legalBodyName/lido:appellationValue)"/>
            </dc:rights>
          </xsl:if>
        </edm:WebResource> 
      </xsl:if>

      <!-- Downloadable videos, provided_video -->
      <xsl:if test="./lido:resourceRepresentation[@lido:type='provided_video']/lido:linkResource[normalize-space(.)!='']">
        <edm:WebResource>
          <xsl:attribute name="rdf:about">      
            <xsl:call-template name="urlencode">
              <xsl:with-param name="url" select="./lido:resourceRepresentation[@lido:type='provided_video']/lido:linkResource[normalize-space(.)!='']"/>
            </xsl:call-template>
          </xsl:attribute>
          <edm:rights>
            <xsl:attribute name="rdf:resource">
              <xsl:call-template name="europeanalic">
                <xsl:with-param name="license" select="$resourceLic"/>
              </xsl:call-template>
            </xsl:attribute>
          </edm:rights>
          <xsl:if test="normalize-space(./lido:rightsResource/lido:creditLine) != ''">
            <dc:creator>
              <xsl:value-of select="normalize-space(./lido:rightsResource/lido:creditLine)"/>
            </dc:creator>
          </xsl:if>
          <xsl:if test="normalize-space(./lido:rightsResource/lido:rightsHolder/lido:legalBodyName/lido:appellationValue) != ''">
            <dc:rights>
              <xsl:value-of select="normalize-space(./lido:rightsResource/lido:rightsHolder/lido:legalBodyName/lido:appellationValue)"/>
            </dc:rights>
          </xsl:if>
        </edm:WebResource> 
      </xsl:if>

      <!-- Text files, preview_text -->
      <xsl:if test="./lido:resourceRepresentation[@lido:type='preview_text']/lido:linkResource[(normalize-space(.)!='') and (@lido:formatResource!='docx')]">
        <edm:WebResource>
          <xsl:attribute name="rdf:about">      
            <xsl:call-template name="urlencode">
              <xsl:with-param name="url" select="./lido:resourceRepresentation[@lido:type='preview_text']/lido:linkResource[(normalize-space(.)!='') and (@lido:formatResource!='docx')]"/>
            </xsl:call-template>
          </xsl:attribute>
          <edm:rights>
            <xsl:attribute name="rdf:resource">
              <xsl:call-template name="europeanalic">
                <xsl:with-param name="license" select="$resourceLic"/>
              </xsl:call-template>
            </xsl:attribute>
          </edm:rights>
          <xsl:if test="normalize-space(./lido:rightsResource/lido:creditLine) != ''">
            <dc:creator>
              <xsl:value-of select="normalize-space(./lido:rightsResource/lido:creditLine)"/>
            </dc:creator>
          </xsl:if>
          <xsl:if test="normalize-space(./lido:rightsResource/lido:rightsHolder/lido:legalBodyName/lido:appellationValue) != ''">
            <dc:rights>
              <xsl:value-of select="normalize-space(./lido:rightsResource/lido:rightsHolder/lido:legalBodyName/lido:appellationValue)"/>
            </dc:rights>
          </xsl:if>
        </edm:WebResource> 
      </xsl:if>

      <!-- Text files, provided_text-->
      <xsl:if test="./lido:resourceRepresentation[@lido:type='provided_text']/lido:linkResource[(normalize-space(.)!='') and (@lido:formatResource!='docx')]">
        <edm:WebResource>
          <xsl:attribute name="rdf:about">      
            <xsl:call-template name="urlencode">
              <xsl:with-param name="url" select="./lido:resourceRepresentation[@lido:type='provided_text']/lido:linkResource[(normalize-space(.)!='') and (@lido:formatResource!='docx')]"/>
            </xsl:call-template>
          </xsl:attribute>
          <edm:rights>
            <xsl:attribute name="rdf:resource">
              <xsl:call-template name="europeanalic">
                <xsl:with-param name="license" select="$resourceLic"/>
              </xsl:call-template>
            </xsl:attribute>
          </edm:rights>
          <xsl:if test="normalize-space(./lido:rightsResource/lido:creditLine) != ''">
            <dc:creator>
              <xsl:value-of select="normalize-space(./lido:rightsResource/lido:creditLine)"/>
            </dc:creator>
          </xsl:if>
          <xsl:if test="normalize-space(./lido:rightsResource/lido:rightsHolder/lido:legalBodyName/lido:appellationValue) != ''">
            <dc:rights>
              <xsl:value-of select="normalize-space(./lido:rightsResource/lido:rightsHolder/lido:legalBodyName/lido:appellationValue)"/>
            </dc:rights>
          </xsl:if>
        </edm:WebResource> 
      </xsl:if>

      <!-- Image files. Choose only one size in preferred order: image_master, image_large, image_original, image_thumb. Include all images of that type. -->
      <xsl:choose>
        <!-- image_master ; best quality display image -->
        <xsl:when test="./lido:resourceRepresentation[@lido:type='image_master']/lido:linkResource[normalize-space(.)!='']">
          <xsl:for-each select="./lido:resourceRepresentation[(@lido:type='image_master') and (normalize-space(./lido:linkResource) != '')]"> 
            <edm:WebResource>
              <xsl:attribute name="rdf:about">      
                <xsl:call-template name="urlencode">
                  <xsl:with-param name="url" select="./lido:linkResource[normalize-space(.)!='']"/>
                </xsl:call-template>
              </xsl:attribute>
              <edm:rights>
                <xsl:attribute name="rdf:resource">
                  <xsl:call-template name="europeanalic">
                    <xsl:with-param name="license" select="$resourceLic"/>
                  </xsl:call-template>
                </xsl:attribute>
              </edm:rights>
              <xsl:if test="normalize-space(../lido:rightsResource/lido:creditLine) != ''">
                <dc:creator>
                  <xsl:value-of select="normalize-space(../lido:rightsResource/lido:creditLine)"/>
                </dc:creator>
              </xsl:if>
              <xsl:if test="normalize-space(../lido:rightsResource/lido:rightsHolder/lido:legalBodyName/lido:appellationValue) != ''">
                <dc:rights>
                  <xsl:value-of select="normalize-space(../lido:rightsResource/lido:rightsHolder/lido:legalBodyName/lido:appellationValue)"/>
                </dc:rights>
              </xsl:if>
            </edm:WebResource> 
          </xsl:for-each>    
        </xsl:when>
        <!-- image_large / large (or link without type attribute, for backward compatibility) ; second best quality display image -->
        <xsl:when test="./lido:resourceRepresentation[(@lido:type='image_large') or not(@lido:type) or (@lido:type='large')]/lido:linkResource[normalize-space(.)!='']">
          <xsl:for-each select="./lido:resourceRepresentation">
            <xsl:if test="((@lido:type='image_large') or not(@lido:type) or (@lido:type='large')) and (normalize-space(./lido:linkResource) != '')">
              <edm:WebResource>
                <xsl:attribute name="rdf:about">
                  <xsl:call-template name="urlencode">
                    <xsl:with-param name="url" select="./lido:linkResource[normalize-space(.)!='']"/>
                  </xsl:call-template>
                </xsl:attribute>
                <edm:rights>
                  <xsl:attribute name="rdf:resource">
                    <xsl:call-template name="europeanalic">
         	      <xsl:with-param name="license" select="$resourceLic"/>
        	    </xsl:call-template>
                  </xsl:attribute>
                </edm:rights>
                <xsl:if test="normalize-space(../lido:rightsResource/lido:creditLine) != ''">
                  <dc:creator>
                    <xsl:value-of select="normalize-space(../lido:rightsResource/lido:creditLine)"/>
                  </dc:creator>
                </xsl:if>
                <xsl:if test="normalize-space(../lido:rightsResource/lido:rightsHolder/lido:legalBodyName/lido:appellationValue) != ''">
                  <dc:rights>
                    <xsl:value-of select="normalize-space(../lido:rightsResource/lido:rightsHolder/lido:legalBodyName/lido:appellationValue)"/>
                  </dc:rights>
                </xsl:if>
              </edm:WebResource>
            </xsl:if>
          </xsl:for-each>
        </xsl:when>
        <!-- image_original ; large original image, might be too heavy sometimes -->
        <xsl:when test="./lido:resourceRepresentation[(@lido:type='image_original') and (normalize-space(./lido:linkResource) != '')]">
          <xsl:for-each select="./lido:resourceRepresentation[(@lido:type='image_original') and (normalize-space(./lido:linkResource) != '')]">
            <edm:WebResource>
              <xsl:attribute name="rdf:about">
                <xsl:call-template name="urlencode">
                  <xsl:with-param name="url" select="./lido:linkResource[normalize-space(.)!='']"/>
                </xsl:call-template>
              </xsl:attribute>
              <edm:rights>
                <xsl:attribute name="rdf:resource">
                  <xsl:call-template name="europeanalic">
                    <xsl:with-param name="license" select="$resourceLic"/>
                  </xsl:call-template>
                </xsl:attribute>
              </edm:rights>
              <xsl:if test="normalize-space(../lido:rightsResource/lido:creditLine) != ''">
                <dc:creator>
                  <xsl:value-of select="normalize-space(../lido:rightsResource/lido:creditLine)"/>
                </dc:creator>
              </xsl:if>
              <xsl:if test="normalize-space(../lido:rightsResource/lido:rightsHolder/lido:legalBodyName/lido:appellationValue) != ''">
                <dc:rights>
                  <xsl:value-of select="normalize-space(../lido:rightsResource/lido:rightsHolder/lido:legalBodyName/lido:appellationValue)"/>
                </dc:rights>
              </xsl:if>
            </edm:WebResource>
          </xsl:for-each>
        </xsl:when>
        <xsl:otherwise>
          <!-- image_thumb ; small thumbnail image, often too low resolution -->
          <xsl:for-each select="./lido:resourceRepresentation[(@lido:type='image_thumb') and (normalize-space(./lido:linkResource)!='')]">
            <edm:WebResource>
              <xsl:attribute name="rdf:about">
                <xsl:call-template name="urlencode">
                  <xsl:with-param name="url" select="./lido:linkResource[normalize-space(.)!='']"/>
                </xsl:call-template>
              </xsl:attribute>
              <edm:rights>
                <xsl:attribute name="rdf:resource">
                  <xsl:call-template name="europeanalic">
                    <xsl:with-param name="license" select="$resourceLic"/>
                  </xsl:call-template>
                </xsl:attribute>
              </edm:rights>
              <xsl:if test="normalize-space(../lido:rightsResource/lido:creditLine) != ''">
                <dc:creator>
                  <xsl:value-of select="normalize-space(../lido:rightsResource/lido:creditLine)"/>
                </dc:creator>
              </xsl:if>
              <xsl:if test="normalize-space(../lido:rightsResource/lido:rightsHolder/lido:legalBodyName/lido:appellationValue) != ''">
                <dc:rights>
                  <xsl:value-of select="normalize-space(../lido:rightsResource/lido:rightsHolder/lido:legalBodyName/lido:appellationValue)"/>
                </dc:rights>
              </xsl:if>
            </edm:WebResource>
          </xsl:for-each>
        </xsl:otherwise>
      </xsl:choose>
    </xsl:for-each>

    <!-- Aggregation --> 

    <ore:Aggregation>
      <!-- Internal identifier for the aggregaton of this record, should be unique within dataset-->
      <xsl:attribute name="rdf:about">
        <xsl:value-of select="concat('#', concat($recordID, ':aggregation'))"/> 
      </xsl:attribute> 

      <edm:aggregatedCHO>
        <!-- Must be same as the rdf:about attribute of ProvidedCHO-->
        <xsl:attribute name="rdf:resource">
          <xsl:value-of select="concat('#', $recordID)"/> 
        </xsl:attribute>
      </edm:aggregatedCHO>

      <!-- edm:dataProvider -->
      <edm:dataProvider xml:lang="en">
        <!-- Configured for each dataset in the properties file -->
        <xsl:value-of select="$data_provider"/>
      </edm:dataProvider>

      <!-- edm:isShownAt, link to an external web page where the object is shown. -->
      <!-- sourceURL parameter is configured for each dataset in properties file. -->
      <!-- With value 'none', no link is added. -->
      <xsl:if test="$sourceURL != 'none'">
        <edm:isShownAt> 
          <xsl:attribute name="rdf:resource"> 
            <xsl:choose>
              <!-- With value 'Sketchfab', use Sketchfab link -->
              <xsl:when test="$sourceURL = 'Sketchfab'">
                <xsl:if test="//lido:administrativeMetadata/lido:resourceWrap/lido:resourceSet/lido:resourceRepresentation[@lido:type='provided_3D']/lido:linkResource[normalize-space(.)!='']">
                  <xsl:value-of select="//lido:administrativeMetadata/lido:resourceWrap/lido:resourceSet/lido:resourceRepresentation[@lido:type='provided_3D']/lido:linkResource[normalize-space(.)!='']"/>
                </xsl:if>
              </xsl:when>
              <xsl:when test="$sourceURL = 'objectWebResource'">
                <!-- With value "objectWebResource", use a link in objectWebResource or generate a link to Finna.fi as fallback -->
                <xsl:choose>
                  <xsl:when test="normalize-space(//lido:descriptiveMetadata/lido:objectRelationWrap/lido:relatedWorksWrap/lido:relatedWorkSet/lido:relatedWork/lido:object/lido:objectWebResource[@xml:lang='en']) != ''">
                    <xsl:value-of select="normalize-space(//lido:descriptiveMetadata/lido:objectRelationWrap/lido:relatedWorksWrap/lido:relatedWorkSet/lido:relatedWork/lido:object/lido:objectWebResource[@xml:lang='en'])"/>
                  </xsl:when>
                  <xsl:when test="normalize-space(//lido:descriptiveMetadata/lido:objectRelationWrap/lido:relatedWorksWrap/lido:relatedWorkSet/lido:relatedWork/lido:object/lido:objectWebResource) != ''">
                    <xsl:value-of select="normalize-space(//lido:descriptiveMetadata/lido:objectRelationWrap/lido:relatedWorksWrap/lido:relatedWorkSet/lido:relatedWork/lido:object/lido:objectWebResource)"/>
                  </xsl:when>
                  <xsl:otherwise>
                    <xsl:value-of select="concat('http://finna.fi', '/Record/', $museum, '.', $recordID, '?lng=en-gb')"/>
                  </xsl:otherwise>
                </xsl:choose>
              </xsl:when>
              <xsl:otherwise>
              <!-- With any other value, generate a link to the configured Finna view -->
                <xsl:value-of select="concat($sourceURL, '/Record/', $museum, '.', $recordID, '?lng=en-gb')"/> 
              </xsl:otherwise>
            </xsl:choose>
          </xsl:attribute>
        </edm:isShownAt>
      </xsl:if>

      <!-- edm:isShownBy -->
      <!-- Should contain the most relevant resource. For 3D files this should be URL to a viewer that is oEmbed compliant -->
      <edm:isShownBy>
        <xsl:attribute name="rdf:resource">
          <xsl:choose>
            <!-- Transform URL to required syntax -->
            <xsl:when test="starts-with($isShownByLink, 'https://sketchfab.com/3d-models')">
              <xsl:value-of select="'https://sketchfab.com/oembed?url='"/>
              <xsl:value-of select="php:function('rawurlencode',string($isShownByLink))"/>
              <xsl:value-of select="'&amp;format=json'"/>
            </xsl:when>
            <xsl:otherwise>
              <xsl:call-template name="urlencode">
                <xsl:with-param name="url" select="$isShownByLink"/>
              </xsl:call-template>
            </xsl:otherwise>
          </xsl:choose>
        </xsl:attribute>
      </edm:isShownBy>

      <!-- edm:hasView -->
      <!-- All other resources than the one in edm:isShownBy -->
      <xsl:for-each select="//lido:administrativeMetadata/lido:resourceWrap/lido:resourceSet">
          <xsl:variable name="resourceLic" select="./lido:rightsResource/lido:rightsType/lido:conceptID[(@lido:type='Copyright') or (@lido:type='copyright')]"/>
          <!-- Add every first 3D, video, sound and text resource -->
          <xsl:if test="./lido:resourceRepresentation[@lido:type='provided_3D']/lido:linkResource[(normalize-space(.)!='') and (.!=$isShownByLink)]">
            <xsl:variable name="viewerLink" select="./lido:resourceRepresentation[@lido:type='provided_3D']/lido:linkResource[(normalize-space(.)!='') and (.!=$isShownByLink)]"/>
            <edm:hasView>
              <xsl:attribute name="rdf:resource">
                <xsl:choose>
                  <xsl:when test="starts-with($viewerLink, 'https://sketchfab.com/3d-models')">
                    <xsl:value-of select="'https://sketchfab.com/oembed?url='"/>
                    <xsl:value-of select="php:function('rawurlencode',string($viewerLink))"/>
                    <xsl:value-of select="'&amp;format=json'"/>
                  </xsl:when>
                  <xsl:otherwise>
                    <xsl:call-template name="urlencode">
                      <xsl:with-param name="url" select="$viewerLink"/>
                    </xsl:call-template>
                  </xsl:otherwise>
                </xsl:choose>
              </xsl:attribute>
            </edm:hasView>
          </xsl:if>
          <xsl:if test="./lido:resourceRepresentation[@lido:type='preview_3D']/lido:linkResource[(normalize-space(.)!='') and (.!=$isShownByLink)]">
            <edm:hasView>
              <xsl:attribute name="rdf:resource">
                <xsl:call-template name="urlencode">
                  <xsl:with-param name="url" select="./lido:resourceRepresentation[@lido:type='preview_3D']/lido:linkResource[(normalize-space(.)!='') and (.!=$isShownByLink)]"/>
                </xsl:call-template>
              </xsl:attribute>
            </edm:hasView>
          </xsl:if>
          <xsl:if test="./lido:resourceRepresentation[@lido:type='preview_video']/lido:linkResource[(normalize-space(.)!='') and (.!=$isShownByLink)]">
            <edm:hasView>
              <xsl:attribute name="rdf:resource">
                <xsl:call-template name="urlencode">
                  <xsl:with-param name="url" select="./lido:resourceRepresentation[@lido:type='preview_video']/lido:linkResource[(normalize-space(.)!='') and (.!=$isShownByLink)]"/>
                </xsl:call-template>
              </xsl:attribute>
            </edm:hasView>
          </xsl:if>
          <xsl:if test="./lido:resourceRepresentation[@lido:type='provided_video']/lido:linkResource[(normalize-space(.)!='') and (.!=$isShownByLink)]">
            <edm:hasView>
              <xsl:attribute name="rdf:resource">
                <xsl:call-template name="urlencode">
                  <xsl:with-param name="url" select="./lido:resourceRepresentation[@lido:type='provided_video']/lido:linkResource[(normalize-space(.)!='') and (.!=$isShownByLink)]"/>
                </xsl:call-template>
              </xsl:attribute>
            </edm:hasView>
          </xsl:if>
          <xsl:if test="./lido:resourceRepresentation[@lido:type='preview_text']/lido:linkResource[(normalize-space(.)!='') and (@lido:formatResource!='docx') and (.!=$isShownByLink)]">
            <edm:hasView>
              <xsl:attribute name="rdf:resource">
                <xsl:call-template name="urlencode">
                  <xsl:with-param name="url" select="./lido:resourceRepresentation[@lido:type='preview_text']/lido:linkResource[(normalize-space(.)!='') and (@lido:formatResource!='docx') and (.!=$isShownByLink)]"/>
                </xsl:call-template>
              </xsl:attribute>
            </edm:hasView>
          </xsl:if>
          <xsl:if test="./lido:resourceRepresentation[@lido:type='provided_text']/lido:linkResource[(normalize-space(.)!='') and (@lido:formatResource!='docx') and (.!=$isShownByLink)]">
            <edm:hasView>
              <xsl:attribute name="rdf:resource">
                <xsl:call-template name="urlencode">
                  <xsl:with-param name="url" select="./lido:resourceRepresentation[@lido:type='provided_text']/lido:linkResource[(normalize-space(.)!='') and (@lido:formatResource!='docx') and (.!=$isShownByLink)]"/>
                </xsl:call-template>
              </xsl:attribute>
            </edm:hasView>
          </xsl:if> 
          <!-- Image files. Choose only one size in preferred order: image_master, image_large, image_original, image_thumb. Include all images of that type. -->
          <xsl:choose>
            <!-- image_master ; best quality display image -->
            <xsl:when test="./lido:resourceRepresentation[@lido:type='image_master']/lido:linkResource[normalize-space(.)!='']">
              <xsl:for-each select="./lido:resourceRepresentation[(@lido:type='image_master') and (./lido:linkResource[(normalize-space(.)!='') and (.!=$isShownByLink)])]"> 
                <edm:hasView>  
                  <xsl:attribute name="rdf:resource">
                    <xsl:call-template name="urlencode">
                      <xsl:with-param name="url" select="./lido:linkResource[(normalize-space(.)!='') and (.!=$isShownByLink)]"/>
                    </xsl:call-template>
                  </xsl:attribute>
                </edm:hasView>
              </xsl:for-each>
            </xsl:when>
            <!-- image_large / large (or link without type attribute, for backward compatibility) ; second best quality display image -->
            <xsl:when test="./lido:resourceRepresentation[(@lido:type='image_large') or not(@lido:type) or (@lido:type='large')]/lido:linkResource[normalize-space(.)!='']">
              <xsl:for-each select="./lido:resourceRepresentation">
                <xsl:if test="((@lido:type='image_large') or not(@lido:type) or (@lido:type='large')) and (./lido:linkResource[(normalize-space(.)!= '') and (.!= $isShownByLink)])">
                  <edm:hasView>
                    <xsl:attribute name="rdf:resource">
                      <xsl:call-template name="urlencode">
                        <xsl:with-param name="url" select="./lido:linkResource[(normalize-space(.)!= '') and (.!= $isShownByLink)]"/>
                      </xsl:call-template>
                    </xsl:attribute>
                  </edm:hasView>
                </xsl:if>
              </xsl:for-each>
            </xsl:when>
            <!-- image_original ; large original image, might be too heavy sometimes -->
            <xsl:when test="./lido:resourceRepresentation[@lido:type='image_original']/lido:linkResource[normalize-space(.)!='']">
              <xsl:for-each select="./lido:resourceRepresentation[(@lido:type='image_original') and (./lido:linkResource[(normalize-space(.)!= '') and (.!=$isShownByLink)])]">
                <edm:hasView>
                  <xsl:attribute name="rdf:resource">
                    <xsl:call-template name="urlencode">
                      <xsl:with-param name="url" select="./lido:linkResource[(normalize-space(.)!='') and (.!=$isShownByLink)]"/>
                    </xsl:call-template>
                  </xsl:attribute>
                </edm:hasView>
              </xsl:for-each>
            </xsl:when>
            <xsl:otherwise>
              <!-- image_thumb ; small thumbnail image, often too low resolution -->
              <xsl:if test="./lido:resourceRepresentation[@lido:type='image_thumb']/lido:linkResource[normalize-space(.)!='']">
                <xsl:for-each select="./lido:resourceRepresentation[(@lido:type='image_thumb') and (./lido:linkResource[(normalize-space(.)!= '') and (.!=$isShownByLink)])]">
                  <edm:hasView>
                    <xsl:attribute name="rdf:resource">
                      <xsl:call-template name="urlencode">
                        <xsl:with-param name="url" select="./lido:linkResource[(normalize-space(.)!='') and (.!=$isShownByLink)]"/>
                      </xsl:call-template>
                    </xsl:attribute>
                  </edm:hasView>
                </xsl:for-each>
              </xsl:if>
            </xsl:otherwise>
          </xsl:choose>
      </xsl:for-each>

      <!-- edm:object, thumbnail image -->
      <xsl:choose>
        <xsl:when test="//lido:administrativeMetadata/lido:resourceWrap/lido:resourceSet/lido:resourceRepresentation[@lido:type='image_master']/lido:linkResource[normalize-space(.)!='']">
          <edm:object>
            <xsl:attribute name="rdf:resource">
              <xsl:call-template name="urlencode">
                <xsl:with-param name="url" select="//lido:administrativeMetadata/lido:resourceWrap/lido:resourceSet/lido:resourceRepresentation[@lido:type='image_master']/lido:linkResource[normalize-space(.)!='']"/>
              </xsl:call-template>
            </xsl:attribute>
          </edm:object>
        </xsl:when>
        <xsl:when test="//lido:administrativeMetadata/lido:resourceWrap/lido:resourceSet/lido:resourceRepresentation[(@lido:type='image_large') or not(@lido:type) or (@lido:type='large')]/lido:linkResource[normalize-space(.)!='']">
          <edm:object>
            <xsl:attribute name="rdf:resource">
              <xsl:call-template name="urlencode">
                <xsl:with-param name="url" select="//lido:administrativeMetadata/lido:resourceWrap/lido:resourceSet/lido:resourceRepresentation[@lido:type='image_large']/lido:linkResource[normalize-space(.)!='']"/>
              </xsl:call-template>
            </xsl:attribute>
          </edm:object>
        </xsl:when>
        <xsl:otherwise>
          <xsl:if test="//lido:administrativeMetadata/lido:resourceWrap/lido:resourceSet/lido:resourceRepresentation[@lido:type='image_thumb']/lido:linkResource[normalize-space(.)!='']">
            <edm:object>
              <xsl:attribute name="rdf:resource">
                <xsl:call-template name="urlencode">
                  <xsl:with-param name="url" select="//lido:administrativeMetadata/lido:resourceWrap/lido:resourceSet/lido:resourceRepresentation[@lido:type='image_thumb']/lido:linkResource[normalize-space(.)!='']"/>
                </xsl:call-template>
              </xsl:attribute>
            </edm:object>
         </xsl:if>
        </xsl:otherwise>
      </xsl:choose>
      
      <!-- edm:provider, configured for each dataset in properties file. -->
      <edm:provider xml:lang="en">
        <xsl:value-of select="$provider"/>
      </edm:provider>

      <!--edm:rights-->
      <!-- Default rights statement for all WebResources without their own edm:rights field. Mandatory. -->
      <!-- Use the rights statement of the first available resource.-->
      <xsl:if test="//lido:administrativeMetadata/lido:resourceWrap/lido:resourceSet/lido:rightsResource/lido:rightsType/lido:conceptID[((@lido:type='Copyright') or (@lido:type='copyright')) and (normalize-space(.)!='')]">
        <edm:rights>
          <xsl:attribute name="rdf:resource">
            <xsl:call-template name="europeanalic">
              <xsl:with-param name="license" select="//lido:administrativeMetadata/lido:resourceWrap/lido:resourceSet/lido:rightsResource/lido:rightsType/lido:conceptID[((@lido:type='Copyright') or (@lido:type='copyright')) and (normalize-space(.)!='')]"/>
            </xsl:call-template>
          </xsl:attribute>
        </edm:rights>
      </xsl:if>
    </ore:Aggregation>

    <!-- svcs:Service for 3D viewers -->
    <xsl:if test="//lido:administrativeMetadata/lido:resourceWrap/lido:resourceSet/lido:resourceRepresentation[@lido:type='provided_3D']/lido:linkResource[contains(., 'sketchfab')]">
      <svcs:Service>
        <xsl:attribute name="rdf:about">      
          <xsl:value-of select="'https://sketchfab.com/oembed'"/>
        </xsl:attribute>
        <dcterms:conformsTo>
          <xsl:attribute name="rdf:resource">      
            <xsl:value-of select="'https://oembed.com/'"/>
          </xsl:attribute>
        </dcterms:conformsTo>
        <rdfs:label>
          <xsl:attribute name="xml:lang">      
            <xsl:value-of select="'en'"/>
          </xsl:attribute>
          <xsl:value-of select="'Sketchfab'"/>
        </rdfs:label>
      </svcs:Service>
    </xsl:if>

    <!-- edm:Place, for places with coordinates-->
    <xsl:for-each select="//lido:eventWrap/lido:eventSet/lido:event/lido:eventPlace">
      <xsl:if test="(normalize-space(./lido:place/lido:namePlaceSet/lido:appellationValue)!='') and (normalize-space(./lido:place/lido:gml/gml:Point/gml:pos)!='')">
        <edm:Place>
          <xsl:attribute name="rdf:about">
            <xsl:value-of select="concat('#', concat(concat($recordID, '_place_'), position()))"/>
          </xsl:attribute>
          <skos:prefLabel>
            <xsl:value-of select="./lido:place/lido:namePlaceSet/lido:appellationValue"/>
          </skos:prefLabel>
          <wgs84_pos:lat>
            <xsl:value-of select="substring-before(./lido:place/lido:gml/gml:Point/gml:pos, ' ')"/>
          </wgs84_pos:lat>
          <wgs84_pos:long>
            <xsl:value-of select="substring-after(./lido:place/lido:gml/gml:Point/gml:pos, ' ')"/>
          </wgs84_pos:long>
        </edm:Place>
      </xsl:if>
    </xsl:for-each>
    
  </xsl:template> 
  
</xsl:stylesheet>
