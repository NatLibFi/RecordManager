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
  <xsl:strip-space elements="*"/>

  <!-- To use this template, properties file must include following info: -->
    <!-- Parameters: $museum, $provider, $data_provider, $default_type, $externalView, $sourceURL  -->
    <!-- PHP functions: str_replace, rawurlencode, mb_strtolower  -->
  
  <!-- Unique identifier -->
  <xsl:variable name="recordID" select="//lido:lidoRecID"/>

  <!-- The resource type handled as the main resource -->
  <xsl:variable name="mainResourceType">
    <xsl:choose>
      <xsl:when test="//lido:resourceRepresentation[@lido:type='provided_3D']/lido:linkResource[text()]">
        <xsl:value-of select="'provided_3D'"/>
      </xsl:when>
      <xsl:when test="//lido:resourceRepresentation[@lido:type='preview_3D']/lido:linkResource[text()]">
        <xsl:value-of select="'preview_3D'"/>
      </xsl:when>
      <xsl:when test="//lido:resourceRepresentation[@lido:type='preview_video']/lido:linkResource[text()]">
        <xsl:value-of select="'preview_video'"/>
      </xsl:when>
      <xsl:when test="//lido:resourceRepresentation[@lido:type='provided_video']/lido:linkResource[text()]">
        <xsl:value-of select="'provided_video'"/>
      </xsl:when>
      <xsl:when test="//lido:resourceRepresentation[@lido:type='preview_sound']/lido:linkResource[text()]">
        <xsl:value-of select="'preview_sound'"/>
      </xsl:when>
      <xsl:when test="//lido:resourceRepresentation[@lido:type='provided_sound']/lido:linkResource[text()]">
        <xsl:value-of select="'provided_sound'"/>
      </xsl:when>
      <xsl:when test="//lido:resourceRepresentation[@lido:type='image_master']/lido:linkResource[text()]">
        <xsl:value-of select="'image_master'"/>
      </xsl:when>
      <xsl:when test="//lido:resourceRepresentation[@lido:type='image_large']/lido:linkResource[text()]">
        <xsl:value-of select="'image_large'"/>
      </xsl:when>
      <xsl:when test="//lido:resourceRepresentation[@lido:type='large']/lido:linkResource[text()]">
        <xsl:value-of select="'large'"/>
      </xsl:when>
      <xsl:when test="//lido:resourceRepresentation[not(@lido:type)]/lido:linkResource[text()]">
        <xsl:value-of select="'no_type'"/>
      </xsl:when>
      <xsl:when test="//lido:resourceRepresentation[@lido:type='preview_text']/lido:linkResource[text() and (@lido:formatResource != 'docx')]">
        <xsl:value-of select="'preview_text'"/>
      </xsl:when>
      <xsl:when test="//lido:resourceRepresentation[@lido:type='provided_text']/lido:linkResource[text() and (@lido:formatResource != 'docx')]">
        <xsl:value-of select="'provided_text'"/>
      </xsl:when>
      <xsl:when test="//lido:resourceRepresentation[@lido:type='image_original']/lido:linkResource[text()]">
        <xsl:value-of select="'image_original'"/>
      </xsl:when>
      <xsl:when test="//lido:resourceRepresentation[@lido:type='image_thumb']/lido:linkResource[text()]">
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
        <xsl:value-of select="normalize-space(//lido:administrativeMetadata/lido:resourceWrap/lido:resourceSet/lido:resourceRepresentation[not(@lido:type)]/lido:linkResource[text()])"/>
      </xsl:when>
      <xsl:when test="$mainResourceType = 'none'">
        <xsl:value-of select="'none'"/>
      </xsl:when>
      <xsl:otherwise>
        <xsl:value-of select="normalize-space(//lido:administrativeMetadata/lido:resourceWrap/lido:resourceSet/lido:resourceRepresentation[@lido:type=$mainResourceType]/lido:linkResource[text()])"/>
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
      <xsl:for-each select="//lido:eventWrap/lido:eventSet/lido:event/lido:eventActor/lido:actorInRole/lido:actor/lido:nameActorSet/lido:appellationValue[text()]"> 
        <xsl:if test="../../../../../../lido:event/lido:eventType/lido:term[(normalize-space(.)='Valmistus') or (normalize-space(.)='valmistus')]">
          <dc:creator>
            <xsl:value-of select="normalize-space(.)"/> 
          </dc:creator> 
        </xsl:if>
      </xsl:for-each>
      
      <!-- dc:description, objectDescriptionSet -->
      <xsl:for-each select="//lido:objectDescriptionWrap/lido:objectDescriptionSet/lido:descriptiveNoteValue[text()]"> 
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
      <xsl:for-each select="//lido:eventWrap/lido:eventSet/lido:event/lido:eventDescriptionSet/lido:descriptiveNoteValue[text()]">
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
      <xsl:for-each select="//lido:eventWrap/lido:eventSet/lido:event/lido:eventMethod/lido:term[text()]">
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
      <xsl:for-each select="//lido:repositoryWrap/lido:repositorySet/lido:workID[text()]"> 
        <dc:identifier>
          <xsl:value-of select="normalize-space(.)" /> 
        </dc:identifier> 
      </xsl:for-each> 

      <!-- dc:language -->
      <xsl:for-each select="//lido:classification[@lido:type='language']/lido:term[text()]">
        <dc:language>
          <xsl:call-template name="validatetextlang">
            <xsl:with-param name="lang" select="normalize-space(.)"/>
          </xsl:call-template>
        </dc:language>
      </xsl:for-each>
      <!-- Add mandatory language field for textual materials -->
      <xsl:if test="not(//lido:classification[@lido:type='language']/lido:term[text()]) and (($mainResourceType = 'preview_text') or ($mainResourceType = 'provided_text'))">
        <dc:language>
          <xsl:value-of select="'fin'"/>
        </dc:language>
      </xsl:if>

      <!-- dc:subject, subject terms -->
      <xsl:for-each select="//lido:objectRelationWrap/lido:subjectWrap/lido:subjectSet/lido:subject/lido:subjectConcept">
        <!-- Links to supported LOD vocabularies -->
        <xsl:variable name="ysoConcept" select="normalize-space(./lido:conceptID[starts-with(normalize-space(.), 'http://www.yso.fi/onto/yso/')])"/>
        <xsl:variable name="iconclassConcept" select="normalize-space(./lido:conceptID[starts-with(normalize-space(.), 'https://iconclass.org/') or starts-with(normalize-space(.), 'http://iconclass.org/')])"/>
        <xsl:choose>
          <xsl:when test="$ysoConcept">
            <dc:subject>
              <xsl:attribute name="rdf:resource">
                <xsl:value-of select="$ysoConcept"/>
              </xsl:attribute>
            </dc:subject>
          </xsl:when>
          <xsl:when test="$iconclassConcept">
            <dc:subject>
              <xsl:attribute name="rdf:resource">
                <xsl:value-of select="'https://iconclass.org/'"/>
                <xsl:value-of select="substring-after($iconclassConcept, '.org/')"/>
              </xsl:attribute>
            </dc:subject>
          </xsl:when>
          <xsl:otherwise>
            <!-- Add terms only if there is no supported URI. -->
            <xsl:for-each select="./lido:term[text()]">
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
      <xsl:for-each select="//lido:objectRelationWrap/lido:subjectWrap/lido:subjectSet/lido:subject/lido:subjectActor/lido:actor/lido:nameActorSet/lido:appellationValue[text()]">
        <dc:subject>
          <xsl:value-of select="normalize-space(.)"/>
        </dc:subject>
      </xsl:for-each>
      
      <!-- dc:title --> 
      <xsl:for-each select="//lido:titleWrap/lido:titleSet/lido:appellationValue[text()]">
        <dc:title>
          <xsl:attribute name="xml:lang">
            <xsl:call-template name="validatelang">
              <xsl:with-param name="lang" select="@xml:lang"/>
            </xsl:call-template>
          </xsl:attribute>
          <xsl:value-of select="normalize-space(.)"/>
        </dc:title>
      </xsl:for-each> 
      <xsl:if test="not(//lido:titleWrap/lido:titleSet/lido:appellationValue[text()])">
        <!-- Add mandatory title -->
        <dc:title>
          <xsl:attribute name="xml:lang">
            <xsl:value-of select="'fi'"/>
          </xsl:attribute>
          <xsl:value-of select="'Ei otsikkoa'"/>
        </dc:title>
      </xsl:if>

      <!-- dc:type, objectWorkType -->
      <xsl:for-each select="//lido:objectWorkTypeWrap/lido:objectWorkType/lido:term[text()]">
        <dc:type>
          <xsl:call-template name="wikidata-worktype">
            <xsl:with-param name="worktype" select="normalize-space(.)"/>
            <xsl:with-param name="lang" select="@xml:lang"/>
          </xsl:call-template>
        </dc:type>
      </xsl:for-each>

      <!-- dc:type, classifications -->
      <xsl:for-each select="//lido:objectClassificationWrap/lido:classificationWrap/lido:classification[(@lido:type != 'language') or not(@lido:type)]/lido:term[text()]">
        <!-- Do not include classifications containing only numbers. -->
        <xsl:if test="normalize-space(translate(., '0123456789', '         ')) != ''">
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
      <xsl:if test="not(//lido:objectWorkTypeWrap/lido:objectWorkType/lido:term[text()]) and not(//lido:classificationWrap/lido:classification[@lido:type != 'language']/lido:term[text()])">
        <dc:type>
          <xsl:call-template name="wikidata-worktype">
            <xsl:with-param name="worktype" select="$default_type"/>
            <xsl:with-param name="lang" select="'fi'"/>
          </xsl:call-template>
        </dc:type>
      </xsl:if>
     
      <!-- dcterms:created, date from creation event --> 
      <xsl:for-each select="//lido:eventWrap/lido:eventSet/lido:event/lido:eventDate/lido:displayDate[text()]">
        <xsl:if test="../../../lido:event/lido:eventType/lido:term[(normalize-space(.)='Valmistus') or (normalize-space(.)='valmistus')]">
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
      <xsl:for-each select="//lido:objectMeasurementsWrap/lido:objectMeasurementsSet">
        <xsl:for-each select="./lido:displayObjectMeasurements[text()]">
          <dcterms:extent>
            <xsl:attribute name="xml:lang">
              <xsl:call-template name="validatelang">
                <xsl:with-param name="lang" select="@xml:lang"/>
              </xsl:call-template>
            </xsl:attribute>
            <xsl:value-of select="normalize-space(.)"/>
          </dcterms:extent>  
        </xsl:for-each>
        <xsl:if test="not(./lido:displayObjectMeasurements[text()])">
          <xsl:for-each select="./lido:objectMeasurements/lido:measurementsSet">
            <xsl:variable name="measurementValue" select="normalize-space(./lido:measurementValue)"/>
            <xsl:if test="$measurementValue">
              <xsl:variable name="measurementType" select="normalize-space(./lido:measurementType)"/>
              <xsl:variable name="measurementUnit" select="normalize-space(./lido:measurementUnit)"/>
              <dcterms:extent>
                <xsl:if test="$measurementType">
                  <xsl:value-of select="$measurementType"/>
                  <xsl:value-of select="': '"/>
                </xsl:if>
                <xsl:value-of select="$measurementValue"/>
                <xsl:if test="$measurementUnit">
                  <xsl:value-of select="' '"/>
                  <xsl:value-of select="$measurementUnit"/>
                </xsl:if>
              </dcterms:extent>  
            </xsl:if>
          </xsl:for-each>
        </xsl:if>  
      </xsl:for-each>
  

      <!-- dcterms:isPartOf, collections -->
      <xsl:for-each select="//lido:objectRelationWrap/lido:relatedWorksWrap/lido:relatedWorkSet/lido:relatedWork/lido:displayObject[text()]">
        <xsl:if test="../../lido:relatedWorkRelType/lido:term[(normalize-space(.)='kokoelma') or (normalize-space(.)='Kokoelma') or (normalize-space(.)='kuuluu kokoelmaan') or (normalize-space(.)='arkisto') or (normalize-space(.)='Arkisto') or (normalize-space(.)='alakokoelma') or (normalize-space(.)='Alakokoelma') or (normalize-space(.)='erityiskokoelma') or (normalize-space(.)='Erityiskokoelma')]">
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
      <xsl:for-each select="//lido:eventWrap/lido:eventSet/lido:event/lido:eventName/lido:appellationValue[text()]">
        <xsl:if test="../../../lido:event/lido:eventType/lido:term[(normalize-space(.)='Näyttely') or (normalize-space(.)='näyttely')]">
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
        <xsl:for-each select="./lido:displayMaterialsTech[text()]">
          <dcterms:medium>
            <xsl:attribute name="xml:lang">
              <xsl:call-template name="validatelang">
                <xsl:with-param name="lang" select="@xml:lang"/>
              </xsl:call-template>
            </xsl:attribute>
            <xsl:value-of select="normalize-space(.)"/>
          </dcterms:medium>
        </xsl:for-each>
        <xsl:if test="not(./lido:displayMaterialsTech[text()])">
          <xsl:for-each select="./lido:materialsTech/lido:termMaterialsTech/lido:term[text()]">
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
      <xsl:for-each select="//lido:eventWrap/lido:eventSet/lido:event/lido:eventPlace/lido:displayPlace[text()]">
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
          <xsl:if test="(./lido:place/lido:namePlaceSet/lido:appellationValue[text()]) and (./lido:place/lido:gml/gml:Point/gml:pos[text()])">
            <dcterms:spatial>
              <xsl:attribute name="rdf:resource"> 
                <xsl:value-of select="concat('#', concat(concat($recordID, '_place_'), position()))"/>
              </xsl:attribute>
            </dcterms:spatial>
          </xsl:if>
      </xsl:for-each>

      <!-- dcterms:spatial, subjectPlace -->
      <xsl:for-each select="//lido:subjectWrap/lido:subjectSet/lido:subject/lido:subjectPlace/lido:displayPlace[text()]">
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
      <xsl:for-each select="//lido:eventWrap/lido:eventSet/lido:event/lido:eventDate/lido:displayDate[text()]">
        <xsl:if test="not(../../lido:eventType/lido:term[(normalize-space(.)='Valmistus') or (normalize-space(.)='valmistus')])">
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
      <xsl:for-each select="//lido:subjectWrap/lido:subjectSet/lido:subject/lido:subjectDate/lido:displayDate[text()]">
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
      <xsl:variable name="resourceLic">
        <xsl:call-template name="europeanalic">
          <xsl:with-param name="license" select="./lido:rightsResource/lido:rightsType/lido:conceptID[(@lido:type='Copyright') or (@lido:type='copyright')]"/>
        </xsl:call-template>
      </xsl:variable>
      <xsl:variable name="rightsHolder" select="normalize-space(./lido:rightsResource/lido:rightsHolder/lido:legalBodyName/lido:appellationValue[text()])"/>
      <xsl:variable name="creditLine" select="normalize-space(./lido:rightsResource/lido:creditLine[text()])"/>

      <!-- Add every first 3D, video, sound and text resource -->
      <!-- Link to 3D viewer, provided_3D -->
      <xsl:variable name="provided3DResource" select="normalize-space(./lido:resourceRepresentation[@lido:type='provided_3D']/lido:linkResource[text()])"/>
      <xsl:if test="$provided3DResource">
        <edm:WebResource>
          <xsl:attribute name="rdf:about">
            <!-- Transform URL to required syntax -->
            <xsl:choose>
              <!-- Links to Sketchfab -->
              <xsl:when test="starts-with($provided3DResource, 'https://sketchfab.com/3d-models')">
                <xsl:value-of select="'https://sketchfab.com/oembed?url='"/>
                <xsl:value-of select="php:function('rawurlencode',string($provided3DResource))"/>
                <xsl:value-of select="'&amp;format=json'"/>
              </xsl:when>
              <!-- Other links -->
              <xsl:otherwise>
                <xsl:call-template name="urlencode">
                  <xsl:with-param name="url" select="$provided3DResource"/>
                </xsl:call-template>
              </xsl:otherwise>
            </xsl:choose>
          </xsl:attribute>
          <!-- Creator of the resource -->
          <xsl:if test="$creditLine">
            <dc:creator>
              <xsl:value-of select="$creditLine"/>
            </dc:creator>
          </xsl:if>
          <!-- Rights holder -->
          <xsl:if test="$rightsHolder">
            <dc:rights>
              <xsl:value-of select="$rightsHolder"/>
            </dc:rights>
          </xsl:if>
          <!-- Mandatory field for 3D: 3D model's intended usage, check possible values: https://data.europeana.eu/vocabulary/usageArea/ -->
          <!-- Currently all materials are in Education category. -->
          <edm:intendedUsage>
            <xsl:attribute name="rdf:resource">      
              <xsl:value-of select="'http://data.europeana.eu/vocabulary/usageArea/Education'"/>
            </xsl:attribute>
          </edm:intendedUsage>
          <!-- Resource rights -->
          <xsl:if test="$resourceLic">
            <edm:rights>
              <xsl:attribute name="rdf:resource">
                <xsl:value-of select="$resourceLic"/>
              </xsl:attribute>
            </edm:rights>
          </xsl:if>
          <!-- Mandatory field for 3D viewer: Viewer service-->
          <xsl:if test="contains($provided3DResource, 'sketchfab')">
            <svcs:has_service>
              <xsl:attribute name="rdf:resource">      
                <xsl:value-of select="'https://sketchfab.com/oembed'"/>
              </xsl:attribute>
            </svcs:has_service>
          </xsl:if>
        </edm:WebResource> 
      </xsl:if>

      <!-- Link to 3D file, preview_3D -->
      <xsl:variable name="preview3DResource" select="normalize-space(./lido:resourceRepresentation[@lido:type='preview_3D']/lido:linkResource[text()])"/>
      <xsl:if test="$preview3DResource">
        <edm:WebResource>
          <xsl:attribute name="rdf:about">      
            <xsl:call-template name="urlencode">
              <xsl:with-param name="url" select="$preview3DResource"/>
            </xsl:call-template>
          </xsl:attribute>
          <!-- Creator of the resource -->
          <xsl:if test="$creditLine">
            <dc:creator>
              <xsl:value-of select="$creditLine"/>
            </dc:creator>
          </xsl:if>
          <!-- Rights holder -->
          <xsl:if test="$rightsHolder">
            <dc:rights>
              <xsl:value-of select="$rightsHolder"/>
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
          <xsl:variable name="polygons" select="normalize-space(./lido:resourceRepresentation[@lido:type='preview_3D']/lido:resourceMeasurementsSet[(./lido:measurementUnit = 'polygons') or (./lido:measurementUnit = 'triangles') or (./lido:measurementUnit = 'faces')]/lido:measurementValue[text()])"/>
          <xsl:if test="$polygons">
            <edm:polygonCount>
              <xsl:value-of select="translate($polygons,' ','')"/>
            </edm:polygonCount>
          </xsl:if>
          <!-- Resource rights -->
          <xsl:if test="$resourceLic">
            <edm:rights>
              <xsl:attribute name="rdf:resource">
                <xsl:value-of select="$resourceLic"/>
              </xsl:attribute>
            </edm:rights>
          </xsl:if>
          <!-- Mandatory field for 3D files: Vertex count -->
          <xsl:variable name="vertices" select="normalize-space(./lido:resourceRepresentation[@lido:type='preview_3D']/lido:resourceMeasurementsSet[./lido:measurementUnit = 'vertices']/lido:measurementValue[text()])"/>
          <xsl:if test="$vertices">
            <edm:vertexCount>
              <xsl:value-of select="translate($vertices,' ','')"/>
            </edm:vertexCount>
          </xsl:if>
        </edm:WebResource> 
      </xsl:if>
  
      <!-- Viewable videos, preview_video -->
      <xsl:variable name="previewVideoResource" select="normalize-space(./lido:resourceRepresentation[@lido:type='preview_video']/lido:linkResource[text()])"/>
      <xsl:if test="$previewVideoResource">
        <edm:WebResource>
          <xsl:attribute name="rdf:about">      
            <xsl:call-template name="urlencode">
              <xsl:with-param name="url" select="$previewVideoResource"/>
            </xsl:call-template>
          </xsl:attribute>
          <!-- Creator of the resource -->
          <xsl:if test="$creditLine">
            <dc:creator>
              <xsl:value-of select="$creditLine"/>
            </dc:creator>
          </xsl:if>
          <!-- Rights holder -->
          <xsl:if test="$rightsHolder">
            <dc:rights>
              <xsl:value-of select="$rightsHolder"/>
            </dc:rights>
          </xsl:if>
          <!-- Resource rights -->
          <xsl:if test="$resourceLic">
            <edm:rights>
              <xsl:attribute name="rdf:resource">
                <xsl:value-of select="$resourceLic"/>
              </xsl:attribute>
            </edm:rights>
          </xsl:if>
        </edm:WebResource> 
      </xsl:if>

      <!-- Downloadable videos, provided_video -->
      <xsl:variable name="providedVideoResource" select="normalize-space(./lido:resourceRepresentation[@lido:type='provided_video']/lido:linkResource[text()])"/>
      <xsl:if test="$providedVideoResource">
        <edm:WebResource>
          <xsl:attribute name="rdf:about">      
            <xsl:call-template name="urlencode">
              <xsl:with-param name="url" select="$providedVideoResource"/>
            </xsl:call-template>
          </xsl:attribute>
          <!-- Creator of the resource -->
          <xsl:if test="$creditLine">
            <dc:creator>
              <xsl:value-of select="$creditLine"/>
            </dc:creator>
          </xsl:if>
          <!-- Rights holder -->
          <xsl:if test="$rightsHolder">
            <dc:rights>
              <xsl:value-of select="$rightsHolder"/>
            </dc:rights>
          </xsl:if>
          <!-- Resource rights -->
          <xsl:if test="$resourceLic">
            <edm:rights>
              <xsl:attribute name="rdf:resource">
                <xsl:value-of select="$resourceLic"/>
              </xsl:attribute>
            </edm:rights>
          </xsl:if>
        </edm:WebResource> 
      </xsl:if>

      <!-- Text files, preview_text -->
      <xsl:variable name="previewTextResource" select="normalize-space(./lido:resourceRepresentation[@lido:type='preview_text']/lido:linkResource[(text()) and (@lido:formatResource != 'docx')])"/>
      <xsl:if test="$previewTextResource">
        <edm:WebResource>
          <xsl:attribute name="rdf:about">      
            <xsl:call-template name="urlencode">
              <xsl:with-param name="url" select="$previewTextResource"/>
            </xsl:call-template>
          </xsl:attribute>
          <!-- Creator of the resource -->
          <xsl:if test="$creditLine">
            <dc:creator>
              <xsl:value-of select="$creditLine"/>
            </dc:creator>
          </xsl:if>
          <!-- Rights holder -->
          <xsl:if test="$rightsHolder">
            <dc:rights>
              <xsl:value-of select="$rightsHolder"/>
            </dc:rights>
          </xsl:if>
          <!-- Resource rights -->
          <xsl:if test="$resourceLic">
            <edm:rights>
              <xsl:attribute name="rdf:resource">
                <xsl:value-of select="$resourceLic"/>
              </xsl:attribute>
            </edm:rights>
          </xsl:if>
        </edm:WebResource> 
      </xsl:if>

      <!-- Text files, provided_text-->
      <xsl:variable name="providedTextResource" select="normalize-space(./lido:resourceRepresentation[@lido:type='provided_text']/lido:linkResource[(text()) and (@lido:formatResource != 'docx')])"/>
      <xsl:if test="$providedTextResource">
        <edm:WebResource>
          <xsl:attribute name="rdf:about">      
            <xsl:call-template name="urlencode">
              <xsl:with-param name="url" select="$providedTextResource"/>
            </xsl:call-template>
          </xsl:attribute>
          <!-- Creator of the resource -->
          <xsl:if test="$creditLine">
            <dc:creator>
              <xsl:value-of select="$creditLine"/>
            </dc:creator>
          </xsl:if>
          <!-- Rights holder -->
          <xsl:if test="$rightsHolder">
            <dc:rights>
              <xsl:value-of select="$rightsHolder"/>
            </dc:rights>
          </xsl:if>
          <!-- Resource rights -->
          <xsl:if test="$resourceLic">
            <edm:rights>
              <xsl:attribute name="rdf:resource">
                <xsl:value-of select="$resourceLic"/>
              </xsl:attribute>
            </edm:rights>
          </xsl:if>
        </edm:WebResource> 
      </xsl:if>

      <!-- Image files. Choose only one size in preferred order: image_master, image_large, image_original, image_thumb. Include all images of that type. -->
      <xsl:variable name="webResourceImageType">
        <xsl:choose>
          <xsl:when test="./lido:resourceRepresentation[@lido:type='image_master']/lido:linkResource[text()]">
            <!-- image_master ; best quality display image -->
            <xsl:value-of select="'image_master'"/>
          </xsl:when>
          <xsl:when test="./lido:resourceRepresentation[@lido:type='image_large']/lido:linkResource[text()]">
            <!-- image_large ; second best quality display image -->
            <xsl:value-of select="'image_large'"/>
          </xsl:when>
          <xsl:when test="./lido:resourceRepresentation[@lido:type='large']/lido:linkResource[text()]">
            <!-- large ; used sometimes instead of image_large, second best quality display image -->
            <xsl:value-of select="'large'"/>
          </xsl:when>
          <xsl:when test="./lido:resourceRepresentation[not(@lido:type)]/lido:linkResource[text()]">
            <!-- link without type attribute ; for backward compatibility -->
            <xsl:value-of select="'no_type'"/>
          </xsl:when>
          <xsl:when test="./lido:resourceRepresentation[@lido:type='image_original']/lido:linkResource[text()]">
            <!-- image_original ; large original image, might be too heavy sometimes -->
            <xsl:value-of select="'image_original'"/>
          </xsl:when>
          <xsl:otherwise>
            <!-- image_thumb ; small thumbnail image, often too low resolution -->
            <xsl:value-of select="'image_thumb'"/>
          </xsl:otherwise>
        </xsl:choose>
      </xsl:variable>
      <xsl:for-each select="./lido:resourceRepresentation">
        <xsl:variable name="linkResource" select="normalize-space(./lido:linkResource[(text())])"/>
        <xsl:if test="$linkResource and (@lido:type=$webResourceImageType or ($webResourceImageType='no_type' and not(@lido:type)))"> 
          <edm:WebResource>
            <xsl:attribute name="rdf:about">      
              <xsl:call-template name="urlencode">
                <xsl:with-param name="url" select="$linkResource"/>
              </xsl:call-template>
            </xsl:attribute>
            <!-- Creator of the resource -->
            <xsl:if test="$creditLine">
              <dc:creator>
                <xsl:value-of select="$creditLine"/>
              </dc:creator>
            </xsl:if>
            <!-- Rights holder -->
            <xsl:if test="$rightsHolder">
              <dc:rights>
                <xsl:value-of select="$rightsHolder"/>
              </dc:rights>
            </xsl:if>
            <!-- Resource rights -->
            <xsl:if test="$resourceLic">
              <edm:rights>
                <xsl:attribute name="rdf:resource">
                  <xsl:value-of select="$resourceLic"/>
                </xsl:attribute>
              </edm:rights>
            </xsl:if>
          </edm:WebResource> 
        </xsl:if>
      </xsl:for-each>
    </xsl:for-each>

    <!-- Aggregation --> 

    <ore:Aggregation>
      <!-- Internal identifier for the aggregation of this record, should be unique within dataset-->
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

      <!-- edm:isShownAt, link to an external web page where the object is displayed. -->
      <!-- externalView and sourceURL parameters are configured for each dataset in properties file. -->
      <!-- sourceURL defines the preferred VuFind instance to be used in isShownAt if not overridden by another external view -->
      <!-- With externalView value 'none', no link is added. -->
      <xsl:if test="$externalView != 'none'">
        <xsl:variable name="vufindRecordUrl" select="concat($sourceURL, '/Record/', $museum, '.', $recordID, '?lng=en-gb')"/>
        <edm:isShownAt> 
          <xsl:attribute name="rdf:resource"> 
            <xsl:choose>
              <!-- With value 'Sketchfab', use Sketchfab link, or generate a link to the preferred VuFind instance as fallback -->
              <xsl:when test="$externalView = 'Sketchfab'">
                <xsl:variable name="SketchfabLink" select="//lido:administrativeMetadata/lido:resourceWrap/lido:resourceSet/lido:resourceRepresentation[@lido:type='provided_3D']/lido:linkResource[contains(., 'sketchfab')]"/>
                <xsl:choose>
                  <xsl:when test="$SketchfabLink">
                    <xsl:value-of select="$SketchfabLink"/>
                  </xsl:when>
                  <xsl:otherwise>
                    <xsl:value-of select="$vufindRecordUrl"/>
                  </xsl:otherwise>
                </xsl:choose>
              </xsl:when>
              <xsl:when test="$externalView = 'objectWebResource'">
                <!-- With value "objectWebResource", use a link in objectWebResource, or generate a link to the preferred VuFind instance as fallback -->
                <xsl:variable name="objectWebResourceEn" select="normalize-space(//lido:descriptiveMetadata/lido:objectRelationWrap/lido:relatedWorksWrap/lido:relatedWorkSet/lido:relatedWork/lido:object/lido:objectWebResource[(@xml:lang='en') and (text())])"/>
                <xsl:variable name="anyObjectWebResource" select="normalize-space(//lido:descriptiveMetadata/lido:objectRelationWrap/lido:relatedWorksWrap/lido:relatedWorkSet/lido:relatedWork/lido:object/lido:objectWebResource[text()])"/>
                <xsl:choose>
                  <xsl:when test="$objectWebResourceEn">
                    <xsl:value-of select="$objectWebResourceEn"/>
                  </xsl:when>
                  <xsl:when test="$anyObjectWebResource">
                    <xsl:value-of select="$anyObjectWebResource"/>
                  </xsl:when>
                  <xsl:otherwise>
                    <xsl:value-of select="$vufindRecordUrl"/>
                  </xsl:otherwise>
                </xsl:choose>
              </xsl:when>
              <xsl:otherwise>
              <!-- With any other value, generate a link to the configured VuFind instance -->
                <xsl:value-of select="$vufindRecordUrl"/> 
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
        <!-- Add every first 3D, video, sound and text resource -->
        <xsl:variable name="provided3DLink" select="normalize-space(./lido:resourceRepresentation[@lido:type='provided_3D']/lido:linkResource[(text()) and (normalize-space(.) != $isShownByLink)])"/>
        <xsl:if test="$provided3DLink">
          <edm:hasView>
            <xsl:attribute name="rdf:resource">
              <xsl:choose>
                <xsl:when test="starts-with($provided3DLink, 'https://sketchfab.com/3d-models')">
                  <xsl:value-of select="'https://sketchfab.com/oembed?url='"/>
                  <xsl:value-of select="php:function('rawurlencode',string($provided3DLink))"/>
                  <xsl:value-of select="'&amp;format=json'"/>
                </xsl:when>
                <xsl:otherwise>
                  <xsl:call-template name="urlencode">
                    <xsl:with-param name="url" select="$provided3DLink"/>
                  </xsl:call-template>
                </xsl:otherwise>
              </xsl:choose>
            </xsl:attribute>
          </edm:hasView>
        </xsl:if>
        <xsl:variable name="preview3DLink" select="normalize-space(./lido:resourceRepresentation[@lido:type='preview_3D']/lido:linkResource[(text()) and (normalize-space(.) != $isShownByLink)])"/>
        <xsl:if test="$preview3DLink">
          <edm:hasView>
            <xsl:attribute name="rdf:resource">
              <xsl:call-template name="urlencode">
                <xsl:with-param name="url" select="$preview3DLink"/>
              </xsl:call-template>
            </xsl:attribute>
          </edm:hasView>
        </xsl:if>
        <xsl:variable name="previewVideoLink" select="normalize-space(./lido:resourceRepresentation[@lido:type='preview_video']/lido:linkResource[(text()) and (normalize-space(.) != $isShownByLink)])"/>
        <xsl:if test="$previewVideoLink">
          <edm:hasView>
            <xsl:attribute name="rdf:resource">
              <xsl:call-template name="urlencode">
                <xsl:with-param name="url" select="$previewVideoLink"/>
              </xsl:call-template>
            </xsl:attribute>
          </edm:hasView>
        </xsl:if>
        <xsl:variable name="providedVideoLink" select="normalize-space(./lido:resourceRepresentation[@lido:type='provided_video']/lido:linkResource[(text()) and (normalize-space(.) != $isShownByLink)])"/>
        <xsl:if test="$providedVideoLink">
          <edm:hasView>
            <xsl:attribute name="rdf:resource">
              <xsl:call-template name="urlencode">
                <xsl:with-param name="url" select="$providedVideoLink"/>
              </xsl:call-template>
            </xsl:attribute>
          </edm:hasView>
        </xsl:if>
        <xsl:variable name="previewTextLink" select="normalize-space(./lido:resourceRepresentation[@lido:type='preview_text']/lido:linkResource[(text()) and (@lido:formatResource != 'docx') and (normalize-space(.) != $isShownByLink)])"/>
        <xsl:if test="$previewTextLink">
          <edm:hasView>
            <xsl:attribute name="rdf:resource">
              <xsl:call-template name="urlencode">
                <xsl:with-param name="url" select="$previewTextLink"/>
              </xsl:call-template>
            </xsl:attribute>
          </edm:hasView>
        </xsl:if>
        <xsl:variable name="providedTextLink" select="normalize-space(./lido:resourceRepresentation[@lido:type='provided_text']/lido:linkResource[(text()) and (@lido:formatResource != 'docx') and (normalize-space(.) != $isShownByLink)])"/>
        <xsl:if test="$providedTextLink">
          <edm:hasView>
            <xsl:attribute name="rdf:resource">
              <xsl:call-template name="urlencode">
                <xsl:with-param name="url" select="$providedTextLink"/>
              </xsl:call-template>
            </xsl:attribute>
          </edm:hasView>
        </xsl:if> 
        <!-- Image files. Choose only one size in preferred order: image_master, image_large, image_original, image_thumb. Include all images of that type. -->
        <xsl:variable name="hasViewImageType">
          <xsl:choose>
            <xsl:when test="./lido:resourceRepresentation[@lido:type='image_master']/lido:linkResource[text()]">
              <!-- image_master ; best quality display image -->
              <xsl:value-of select="'image_master'"/>
            </xsl:when>
            <xsl:when test="./lido:resourceRepresentation[@lido:type='image_large']/lido:linkResource[text()]">
              <!-- image_large ; second best quality display image -->
              <xsl:value-of select="'image_large'"/>
            </xsl:when>
            <xsl:when test="./lido:resourceRepresentation[@lido:type='large']/lido:linkResource[text()]">
              <!-- large ; used sometimes instead of image_large, second best quality display image -->
              <xsl:value-of select="'large'"/>
            </xsl:when>
            <xsl:when test="./lido:resourceRepresentation[not(@lido:type)]/lido:linkResource[text()]">
              <!-- link without type attribute ; for backward compatibility -->
              <xsl:value-of select="'no_type'"/>
            </xsl:when>
            <xsl:when test="./lido:resourceRepresentation[@lido:type='image_original']/lido:linkResource[text()]">
              <!-- image_original ; large original image, might be too heavy sometimes -->
              <xsl:value-of select="'image_original'"/>
            </xsl:when>
            <xsl:otherwise>
              <!-- image_thumb ; small thumbnail image, often too low resolution -->
              <xsl:value-of select="'image_thumb'"/>
            </xsl:otherwise>
          </xsl:choose>
        </xsl:variable>
        <xsl:for-each select="./lido:resourceRepresentation">
          <xsl:variable name="linkResource" select="normalize-space(./lido:linkResource[(text()) and (normalize-space(.) != $isShownByLink)])"/>
          <xsl:if test="$linkResource and (@lido:type=$hasViewImageType or ($hasViewImageType='no_type' and not(@lido:type)))">
            <edm:hasView>
              <xsl:attribute name="rdf:resource">
                <xsl:call-template name="urlencode">
                  <xsl:with-param name="url" select="$linkResource"/>
                </xsl:call-template>
              </xsl:attribute>
            </edm:hasView>
          </xsl:if>
        </xsl:for-each>
      </xsl:for-each>

      <!-- edm:object, thumbnail image -->
      <xsl:variable name="objectMaster" select="normalize-space(//lido:resourceRepresentation[@lido:type='image_master']/lido:linkResource[text()])"/>
      <xsl:variable name="objectLarge" select="normalize-space(//lido:resourceRepresentation[(@lido:type='image_large') or not(@lido:type) or (@lido:type='large')]/lido:linkResource[text()])"/>
      <xsl:variable name="objectThumb" select="normalize-space(//lido:resourceRepresentation[@lido:type='image_thumb']/lido:linkResource[text()])"/>
      <xsl:variable name="objectLink">
        <xsl:choose>
          <xsl:when test="$objectMaster">
            <xsl:value-of select="$objectMaster"/>
          </xsl:when>
          <xsl:when test="$objectLarge">
            <xsl:value-of select="$objectLarge"/>
          </xsl:when>
          <xsl:when test="$objectThumb">
            <xsl:value-of select="$objectThumb"/>
          </xsl:when>
          <xsl:otherwise>
            <xsl:value-of select="'none'"/>
          </xsl:otherwise>
        </xsl:choose>
      </xsl:variable>
      <xsl:if test="$objectLink != 'none'">
        <edm:object>
          <xsl:attribute name="rdf:resource">
            <xsl:call-template name="urlencode">
              <xsl:with-param name="url" select="$objectLink"/>
            </xsl:call-template>
          </xsl:attribute>
        </edm:object>
      </xsl:if>
      
      <!-- edm:provider, configured for each dataset in properties file. -->
      <edm:provider xml:lang="en">
        <xsl:value-of select="$provider"/>
      </edm:provider>

      <!--edm:rights-->
      <!-- Default rights statement for all WebResources without their own edm:rights field. Mandatory. -->
      <!-- Use the rights statement of the first available resource.-->
      <xsl:variable name="defaultRights" select="//lido:administrativeMetadata/lido:resourceWrap/lido:resourceSet/lido:rightsResource/lido:rightsType/lido:conceptID[((@lido:type='Copyright') or (@lido:type='copyright')) and (text())]"/>
      <xsl:if test="$defaultRights">
        <edm:rights>
          <xsl:attribute name="rdf:resource">
            <xsl:call-template name="europeanalic">
              <xsl:with-param name="license" select="$defaultRights"/>
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
      <xsl:variable name="placeName" select="normalize-space(./lido:place/lido:namePlaceSet/lido:appellationValue[text()])"/>
      <xsl:variable name="placeGml" select="normalize-space(./lido:place/lido:gml/gml:Point/gml:pos[text()])"/>
      <xsl:if test="$placeName and $placeGml">
        <edm:Place>
          <xsl:attribute name="rdf:about">
            <xsl:value-of select="concat('#', concat(concat($recordID, '_place_'), position()))"/>
          </xsl:attribute>
          <skos:prefLabel>
            <xsl:value-of select="$placeName"/>
          </skos:prefLabel>
          <wgs84_pos:lat>
            <xsl:value-of select="substring-before($placeGml, ' ')"/>
          </wgs84_pos:lat>
          <wgs84_pos:long>
            <xsl:value-of select="substring-after($placeGml, ' ')"/>
          </wgs84_pos:long>
        </edm:Place>
      </xsl:if>
    </xsl:for-each>
    
  </xsl:template> 
  
</xsl:stylesheet>
