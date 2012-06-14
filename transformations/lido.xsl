<xsl:stylesheet version="1.0"
    xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
    xmlns:php="http://php.net/xsl"
    xmlns:xlink="http://www.w3.org/2001/XMLSchema-instance"
    xmlns:ex="http://exslt.org/dates-and-times" 
    extension-element-prefixes="ex">
    <xsl:output method="xml" indent="yes" encoding="utf-8"/>
    <!-- Provide option to omit the <add> wrap for bulk inserts -->
    <xsl:template match="lidoWrap">
        <xsl:choose>
         <xsl:when test="$bulk">        
              <xsl:apply-templates/>
         </xsl:when>
         <xsl:otherwise>
            <add><xsl:apply-templates/></add>
         </xsl:otherwise>
      </xsl:choose>
    </xsl:template>
    
    <xsl:template match="lido">
        <doc>
            <!-- ID -->
            <field name="id">
                <xsl:value-of select="php:function('LidoRousku::normalizeId', string(//lido/lidoRecID))"/>
            </field>
            <field name="title">
            <xsl:choose>
                <xsl:when test="//lido/descriptiveMetadata/objectIdentificationWrap/titleWrap/titleSet[1]/appellationValue[@xml:lang='fi']">
                    <xsl:value-of select="//lido/descriptiveMetadata/objectIdentificationWrap/titleWrap/titleSet[1]/appellationValue[@xml:lang='fi']"/>
                </xsl:when>
                <xsl:otherwise>
                    <xsl:value-of select="//lido/descriptiveMetadata/objectIdentificationWrap/titleWrap/titleSet[1]/appellationValue"/>
                </xsl:otherwise>
            </xsl:choose>
            </field>
            <field name="title_short">
            <xsl:choose>
                <xsl:when test="//lido/descriptiveMetadata/objectIdentificationWrap/titleWrap/titleSet[1]/appellationValue[@xml:lang='fi']">
                    <xsl:value-of select="//lido/descriptiveMetadata/objectIdentificationWrap/titleWrap/titleSet[1]/appellationValue[@xml:lang='fi']"/>
                </xsl:when>
                <xsl:otherwise>
                    <xsl:value-of select="//lido/descriptiveMetadata/objectIdentificationWrap/titleWrap/titleSet[1]/appellationValue"/>
                </xsl:otherwise>
            </xsl:choose>
            </field>
            <field name="title_sub">
            <xsl:choose>
                <xsl:when test="//lido/descriptiveMetadata/objectIdentificationWrap/titleWrap/titleSet[2]/appellationValue[@xml:lang='fi']">
                    <xsl:value-of select="//lido/descriptiveMetadata/objectIdentificationWrap/titleWrap/titleSet[2]/appellationValue[@xml:lang='fi']"/>
                </xsl:when>
                <xsl:otherwise>
                    <xsl:value-of select="//lido/descriptiveMetadata/objectIdentificationWrap/titleWrap/titleSet[2]/appellationValue"/>
                </xsl:otherwise>
            </xsl:choose>
            </field>
            <field name="format">
                <xsl:value-of select="php:function('ucfirst', string(//lido/descriptiveMetadata/objectClassificationWrap/objectWorkTypeWrap/objectWorkType/term))"/>
            </field>
            
            <xsl:if test="string(//lido/descriptiveMetadata/objectIdentificationWrap/repositoryWrap/repositorySet/repositoryName/legalBodyName/appellationValue)">
                <field name="institution">
                    <xsl:value-of select="php:function('LidoRousku::untilSlash', string(//lido/descriptiveMetadata/objectIdentificationWrap/repositoryWrap/repositorySet/repositoryName/legalBodyName/appellationValue))"/>
                </field>
            </xsl:if>
            
            <xsl:if test="string(//lido/descriptiveMetadata/objectIdentificationWrap/repositoryWrap/repositorySet/repositoryName/legalBodyName/appellationValue)">
                <field name="rights">
                    <xsl:value-of select="string(//lido/descriptiveMetadata/objectIdentificationWrap/repositoryWrap/repositorySet/repositoryName/legalBodyName/appellationValue)"/>
                </field>
            </xsl:if>
            
            <xsl:if test="//lido/descriptiveMetadata/objectIdentificationWrap/objectDescriptionWrap/objectDescriptionSet/descriptiveNoteValue">
            <field name="description">
                <xsl:value-of select="//lido/descriptiveMetadata/objectIdentificationWrap/objectDescriptionWrap/objectDescriptionSet/descriptiveNoteValue"/>
            </field>
            </xsl:if>
            
            <xsl:if test="//lido/descriptiveMetadata/objectIdentificationWrap/objectMeasurementsWrap/objectMeasurementsSet/displayObjectMeasurements">
                <field name="measurements">
                    <xsl:value-of select="//lido/descriptiveMetadata/objectIdentificationWrap/objectMeasurementsWrap/objectMeasurementsSet/displayObjectMeasurements"/>
                </field>
            </xsl:if>
            <xsl:for-each select="//lido/descriptiveMetadata/eventWrap/eventSet[not(event/eventType/term = preceding-sibling::eventSet/event/eventType/term)]">
                <xsl:for-each select="event">
                <xsl:variable name="fieldName" select="php:function('LidoRousku::mapEventType', string(eventType/term))"/>
                <xsl:if test="$fieldName">
                    <xsl:if test="eventActor/actorInRole/actor/nameActorSet/appellationValue">                                
                        <field name="event_{$fieldName}_actor_str"><xsl:if test="eventActor/actorInRole/roleActor/term"></xsl:if><xsl:value-of select="eventActor/actorInRole/actor/nameActorSet/appellationValue"/></field>
                    </xsl:if>
                    
                    <xsl:if test="eventDate/displayDate">
                        <field name="event_{$fieldName}_displaydate_str"><xsl:value-of select="eventDate/displayDate"/></field>
                    </xsl:if>
                    
                    <xsl:choose>
                    <xsl:when test="eventDate/date/earliestDate and eventDate/date/latestDate">
                        <xsl:variable name="earliest" select="php:function('LidoRousku::normalizeRangeStart', string(eventDate/date/earliestDate))"/>
                        <xsl:variable name="latest" select="php:function('LidoRousku::normalizeRangeEnd', string(eventDate/date/latestDate))"/>
                        <xsl:if test="$latest and $earliest">
                            <field name="event_{$fieldName}_daterange"><xsl:value-of select="$earliest"/>,<xsl:value-of select="$latest"/></field>
                        </xsl:if>
                    </xsl:when>
                    <xsl:otherwise>
                        <xsl:if test="eventDate/displayDate">
                            <xsl:variable name="normalizeDateResult" select="php:function('LidoRousku::normalizeDate', string(eventDate/displayDate))"/>
                            <xsl:if test="$normalizeDateResult">
                                <field name="event_{$fieldName}_daterange"><xsl:value-of select="php:function('LidoRousku::normalizeDate', string(eventDate/displayDate))"/></field>
                            </xsl:if>
                        </xsl:if>
                    </xsl:otherwise>
                    </xsl:choose>
                    
                    <xsl:if test="eventPlace/displayPlace">
                        <xsl:if test="$geoCoding">
                            <xsl:variable name="geoCodeResult" select="php:function('LidoGeocode::_geoCode', string(eventPlace/displayPlace))"/>
                                <xsl:choose>
                                <xsl:when test="$geoCodeResult">
                                    <xsl:variable name="koordinaatit" select="php:function('LidoGeocode::getKoordinaatit', $geoCodeResult)"/>
                                    <xsl:if test="$koordinaatit">
                                        <field name="event_{$fieldName}_place_coords"><xsl:value-of select="$koordinaatit"/></field>
                                    </xsl:if>
                                    
                                    <xsl:variable name="city" select="php:function('LidoGeocode::getKunta', $geoCodeResult)"/>
                                    <xsl:if test="$city">
                                        <field name="event_{$fieldName}_place_city_txtF"><xsl:value-of select="$city"/></field>
                                    </xsl:if>
                                    
                                    <xsl:variable name="state" select="php:function('LidoGeocode::getMaakunta', $geoCodeResult)"/>
                                    <xsl:if test="$state">
                                        <field name="event_{$fieldName}_place_state_txtF"><xsl:value-of select="$state"/></field>
                                    </xsl:if>
                                    
                                    <xsl:variable name="country" select="php:function('LidoGeocode::getMaa', $geoCodeResult)"/>
                                    <xsl:if test="$country">
                                        <field name="event_{$fieldName}_place_country_txtF"><xsl:value-of select="$country"/></field>
                                    </xsl:if>
                                    <field name="event_{$fieldName}_place_notfound_boolean">false</field>
                                </xsl:when>
                                <xsl:otherwise>
                                    <field name="event_{$fieldName}_place_notfound_boolean">true</field>
                                </xsl:otherwise>
                            </xsl:choose>
                        </xsl:if>
                        
                        <field name="event_{$fieldName}_displayplace_str"><xsl:value-of select="eventPlace/displayPlace"/></field>
                    </xsl:if>
                    
                </xsl:if>
                </xsl:for-each>
            </xsl:for-each>
            
            <!-- RECORDTYPE -->
            <field name="recordtype">lido</field>
            
            <!-- <xsl:for-each select="//lido/descriptiveMetadata/objectClassificationWrap/classificationWrap/classification/term">
                <field name="topic"><xsl:value-of select="."/></field>
            </xsl:for-each> -->
            
            <xsl:for-each select="//lido/descriptiveMetadata/objectRelationWrap/subjectWrap/subjectSet/subject[not(@type='iconclass')]/subjectConcept/term">
            <field name="topic">
                <xsl:choose>
                    <xsl:when test="$onki_rikastus and @label">
                        <xsl:variable name="onkiResult" select="normalize-space(php:function('LidoRousku::getHierarchy', $onki_apikey, string(@label), string(.)))"/>
                        <xsl:choose>
                            <xsl:when test="$onkiResult">
                                <xsl:value-of select="$onkiResult"/>
                            </xsl:when>
                            <xsl:otherwise>
                                <xsl:value-of select="normalize-space(.)"/>
                            </xsl:otherwise>
                        </xsl:choose>
                    </xsl:when>
                    <xsl:otherwise>
                        <xsl:value-of select="normalize-space(.)"/>
                    </xsl:otherwise>
                </xsl:choose>
            </field>
            </xsl:for-each>
            <!--
            <xsl:for-each select="//lido/descriptiveMetadata/objectClassificationWrap/classificationWrap/classification">
                    <xsl:for-each select="term">
                    <field name="topic"><xsl:value-of select="."/></field>
                    </xsl:for-each>
            </xsl:for-each>-->
            
            <xsl:if test="//lido/descriptiveMetadata/objectRelationWrap/relatedWorksWrap/relatedWorkSet[relatedWorkRelType/term='Kokoelma']/relatedWork/displayObject">
            <field name="collection"><xsl:value-of select="//lido/descriptiveMetadata/objectRelationWrap/relatedWorksWrap/relatedWorkSet[relatedWorkRelType/term='Kokoelma']/relatedWork/displayObject"/></field>
            </xsl:if>
            
            <xsl:if test="//lido/administrativeMetadata/resourceWrap/resourceSet/resourceRepresentation/linkResource">
            <field name="thumbnail"><xsl:value-of select="//lido/administrativeMetadata/resourceWrap/resourceSet/resourceRepresentation/linkResource"/></field>
            </xsl:if>
            
            <xsl:if test="//lido/descriptiveMetadata/eventWrap/eventSet/event[eventType/term='valmistus']/eventMaterialsTech/materialsTech/termMaterialsTech/term">
            <xsl:for-each select="//lido/descriptiveMetadata/eventWrap/eventSet/event[eventType/term='valmistus']/eventMaterialsTech/materialsTech/termMaterialsTech/term">
                <field name="material"><xsl:if test="../../extentMaterialsTech"></xsl:if><xsl:value-of select="."/></field>
            </xsl:for-each>
            </xsl:if>
            
            <xsl:if test="//lido/descriptiveMetadata/eventWrap/eventSet/event[eventType/term='valmistus']/culture/term">
                <field name="culture"><xsl:value-of select="//lido/descriptiveMetadata/eventWrap/eventSet/event[eventType/term='valmistus']/culture/term"/></field>
            </xsl:if>
            <field name="identifier"><xsl:value-of select="//lido/descriptiveMetadata/objectIdentificationWrap/repositoryWrap/repositorySet/workID"/></field>
        </doc>
    </xsl:template>
</xsl:stylesheet>
