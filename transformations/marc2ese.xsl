<?xml version="1.0" encoding="UTF-8"?>
<xsl:stylesheet version="1.0" xmlns="http://www.loc.gov/MARC21/slim"
                xmlns:europeana="http://www.europeana.eu/schemas/ese/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
                xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
                xmlns:dcterms="http://purl.org/dc/terms/">

    <!--## MARC21XML to ESE. National Library of Finland, 2012. Based on marc2ese.xsl from joai-mzk project ##-->                

    <xsl:import href="MARC21slimUtils.xsl" />
    <xsl:output method="xml" indent="yes" omit-xml-declaration="yes"/>
    
    <xsl:template match="/">
        <xsl:if test="collection">
            <!--##europeana:metadata xsi:schemaLocation="http://www.europeana.eu/schemas/ese/ http://www.europeana.eu/schemas/ese/ESE-V3.2.xsd"##-->
			<europeana:metadata xsi:schemaLocation="http://www.europeana.eu/schemas/ese/ http://www.europeana.eu/schemas/ese/ESE-V3.4.xsd">
                <xsl:for-each select="collection">
                    <xsl:for-each select="record">
                        <europeana:record>
                            <dc:source><xsl:value-of select="$source"/></dc:source>
                            <xsl:apply-templates select="." />
                        </europeana:record>
                    </xsl:for-each>
                </xsl:for-each>
            </europeana:metadata>
        </xsl:if>

        <xsl:if test="record">
            <europeana:metadata
                xsi:schemaLocation="http://www.europeana.eu/schemas/ese/ http://www.europeana.eu/schemas/ese/ESE-V3.4.xsd">
                <europeana:record>
                    <dc:source><xsl:value-of select="$source"/></dc:source>
                    <xsl:apply-templates />
                </europeana:record>
            </europeana:metadata>

        </xsl:if>
    </xsl:template>
    <xsl:template match="record">
        <xsl:variable name="leader" select="leader" />
        <xsl:variable name="leader6" select="substring($leader,7,1)" />
        <xsl:variable name="leader7" select="substring($leader,8,1)" />
        <!--<xsl:variable name="controlField008" select="controlfield[@tag=008]" />-->
        <dc:identifier>
            <xsl:value-of select="controlfield[@tag='003']"/><xsl:value-of select="$id_prefix"/><xsl:value-of select="controlfield[@tag='001']"/>
        </dc:identifier>
		<xsl:for-each select="datafield[@tag=020]">
            <dc:identifier>
              <xsl:if test="subfield[@code='a']"><xsl:value-of select="subfield[@code='a']"/></xsl:if>
            </dc:identifier>
        </xsl:for-each>
		<xsl:for-each select="datafield[@tag=022]">
            <dc:identifier>
              <xsl:if test="subfield[@code='a']"><xsl:value-of select="subfield[@code='a']"/></xsl:if>
            </dc:identifier>
        </xsl:for-each>
		<xsl:for-each select="datafield[@tag=028]">
            <dc:identifier>
              <xsl:if test="subfield[@code='b']"> <xsl:value-of select="subfield[@code='b']"/></xsl:if>
              <xsl:if test="subfield[@code='a']"> <xsl:value-of select="subfield[@code='a']"/></xsl:if>
            </dc:identifier>
        </xsl:for-each>
		<xsl:for-each select="datafield[@tag=036]">
            <dc:identifier>
              <xsl:if test="subfield[@code='a']"> <xsl:value-of select="subfield[@code='a']"/></xsl:if>
              <xsl:if test="subfield[@code='b']"> <xsl:value-of select="subfield[@code='b']"/></xsl:if>			  
            </dc:identifier>
        </xsl:for-each>		
        <xsl:for-each select="datafield[@tag=245]">
            <dc:title>
              <xsl:value-of select="subfield[@code='a']"/>
              <xsl:if test="subfield[@code='b']"> <xsl:value-of select="subfield[@code='b']"/></xsl:if>
              <xsl:if test="subfield[@code='n']"> <xsl:value-of select="subfield[@code='n']"/></xsl:if>
              <xsl:if test="subfield[@code='p']"> <xsl:value-of select="subfield[@code='p']"/></xsl:if>
              <xsl:if test="subfield[@code='f']"> <xsl:value-of select="subfield[@code='f']"/></xsl:if>
              <xsl:if test="subfield[@code='g']"> <xsl:value-of select="subfield[@code='g']"/></xsl:if>
              <xsl:if test="subfield[@code='k']"> <xsl:value-of select="subfield[@code='k']"/></xsl:if>
            </dc:title>
        </xsl:for-each>
    	<xsl:for-each select="datafield[@tag=130]|datafield[@tag=240]">
            <dcterms:alternative>
              <xsl:if test="subfield[@code='a']"> <xsl:value-of select="subfield[@code='a']"/></xsl:if>
              <xsl:if test="subfield[@code='m']"> <xsl:value-of select="subfield[@code='m']"/></xsl:if>
              <xsl:if test="subfield[@code='n']"> <xsl:value-of select="subfield[@code='n']"/></xsl:if>
              <xsl:if test="subfield[@code='r']"> <xsl:value-of select="subfield[@code='r']"/></xsl:if>
              <xsl:if test="subfield[@code='g']"> <xsl:value-of select="subfield[@code='g']"/></xsl:if>
              <xsl:if test="subfield[@code='p']"> <xsl:value-of select="subfield[@code='p']"/></xsl:if>
              <xsl:if test="subfield[@code='o']"> <xsl:value-of select="subfield[@code='o']"/></xsl:if>
              <xsl:if test="subfield[@code='l']"> <xsl:value-of select="subfield[@code='l']"/></xsl:if>
              <xsl:if test="subfield[@code='k']"> <xsl:value-of select="subfield[@code='k']"/></xsl:if>
            </dcterms:alternative>
        </xsl:for-each>	
        <xsl:for-each select="datafield[@tag=246]|datafield[@tag=247]">
            <dcterms:alternative>
              <xsl:if test="subfield[@code='i']"> <xsl:value-of select="subfield[@code='i']"/></xsl:if>
              <xsl:if test="subfield[@code='a']"> <xsl:value-of select="subfield[@code='a']"/></xsl:if>
              <xsl:if test="subfield[@code='b']"> <xsl:value-of select="subfield[@code='b']"/></xsl:if>
              <xsl:if test="subfield[@code='f']"> <xsl:value-of select="subfield[@code='f']"/></xsl:if>
              <xsl:if test="subfield[@code='n']"> <xsl:value-of select="subfield[@code='n']"/></xsl:if>
              <xsl:if test="subfield[@code='p']"> <xsl:value-of select="subfield[@code='p']"/></xsl:if>
            </dcterms:alternative>
        </xsl:for-each>	
        <xsl:for-each select="datafield[@tag=100]|datafield[@tag=700]">
			<dc:creator>
              <xsl:if test="subfield[@code='a']"> <xsl:value-of select="subfield[@code='a']"/></xsl:if>
              <xsl:if test="subfield[@code='c']"> <xsl:value-of select="subfield[@code='c']"/></xsl:if>
            </dc:creator>
        </xsl:for-each>
		<xsl:for-each select="datafield[@tag=110]|datafield[@tag=111]|datafield[@tag=710]|datafield[@tag=711]|datafield[@tag=720]">
			<dc:creator>
              <xsl:if test="subfield[@code='a']"> <xsl:value-of select="subfield[@code='a']"/></xsl:if>
              <xsl:if test="subfield[@code='b']"> <xsl:value-of select="subfield[@code='b']"/></xsl:if>
            </dc:creator>
        </xsl:for-each>
        <dc:type>
            <xsl:if test="$leader7='c'"><xsl:text>collection</xsl:text></xsl:if>
            <xsl:if test="$leader6='d' or $leader6='f' or $leader6='p' or $leader6='t'"><xsl:text>manuscript</xsl:text></xsl:if>
            <xsl:choose>
                <xsl:when test="$leader6='a' or $leader6='t'">text</xsl:when>
                <xsl:when test="$leader6='e' or $leader6='f'">cartographic</xsl:when>
                <xsl:when test="$leader6='c' or $leader6='d'">notated music</xsl:when>
                <xsl:when test="$leader6='i' or $leader6='j'">sound recording</xsl:when>
                <xsl:when test="$leader6='k'">still image</xsl:when>
                <xsl:when test="$leader6='g'">moving image</xsl:when>
                <xsl:when test="$leader6='r'">three dimensional object</xsl:when>
                <xsl:when test="$leader6='m'">software, multimedia</xsl:when>
                <xsl:when test="$leader6='p'">mixed material</xsl:when>
            </xsl:choose>
        </dc:type>
        <!--##<xsl:for-each select="datafield[@tag=655]">
            <dc:type><xsl:value-of select="." /></dc:type>
        </xsl:for-each>##-->
        <xsl:for-each select="datafield[@tag=260]">
            <dc:publisher>
				<xsl:if test="subfield[@code='a']"> <xsl:value-of select="subfield[@code='a']"/> </xsl:if>
				<xsl:if test="subfield[@code='b']"> <xsl:value-of select="subfield[@code='b']"/> </xsl:if>
            </dc:publisher>
        </xsl:for-each>
		<!--##if not using 008-year##-->
        <!--##<xsl:for-each select="datafield[@tag=260]">
            <dc:date>
                <xsl:if test="subfield[@code='c']">&#160;<xsl:value-of select="subfield[@code='c']"/> </xsl:if>
            </dc:date>	
        </xsl:for-each>##-->
		<dc:date>
          <xsl:choose>
	    	<xsl:when test="controlfield[@tag='008']">
              <xsl:value-of select="substring(controlfield[@tag='008'], 8, 4)"/>
           </xsl:when>	
          </xsl:choose>
		</dc:date>
		<xsl:for-each select="datafield[@tag=033]">
            <dcterms:created>
              <xsl:if test="subfield[@code='a']"><xsl:call-template name="format_date"><xsl:with-param name="date" select="subfield[@code='a']"/></xsl:call-template></xsl:if>
            </dcterms:created>
        </xsl:for-each>		
        <!--##<dc:language><xsl:value-of select="substring($controlField008,36,3)" /></dc:language>##-->
		<xsl:for-each select="datafield[@tag=041]">
            <dc:language>
              <xsl:if test="subfield[@code='a']"><xsl:value-of select="subfield[@code='a']"/></xsl:if>
              <xsl:if test="subfield[@code='d']"><xsl:value-of select="subfield[@code='d']"/></xsl:if>			  
            </dc:language>
        </xsl:for-each>
        <xsl:for-each select="datafield[@tag=306]">
            <dcterms:extent>
              <xsl:if test="subfield[@code='a']"><xsl:value-of select="subfield[@code='a']"/></xsl:if>	  
            </dcterms:extent>
        </xsl:for-each>			
        <xsl:for-each
            select="datafield[@tag=500]">
            <dc:description>
    			<xsl:if test="subfield[@code='a']"><xsl:value-of select="subfield[@code='a']"/> </xsl:if>
            </dc:description>
        </xsl:for-each>
        <xsl:for-each
            select="datafield[@tag=501]">
            <dc:description>
    			<xsl:if test="subfield[@code='a']"><xsl:value-of select="subfield[@code='a']"/> </xsl:if>
            </dc:description>
        </xsl:for-each>
        <xsl:for-each
            select="datafield[@tag=502]">
            <dc:description>
    			<xsl:if test="subfield[@code='a']"> <xsl:value-of select="subfield[@code='a']"/> </xsl:if>
    			<xsl:if test="subfield[@code='b']"> <xsl:value-of select="subfield[@code='b']"/> </xsl:if>
    			<xsl:if test="subfield[@code='c']"> <xsl:value-of select="subfield[@code='c']"/> </xsl:if>
    			<xsl:if test="subfield[@code='d']"> <xsl:value-of select="subfield[@code='d']"/> </xsl:if>				
            </dc:description>
        </xsl:for-each>
        <xsl:for-each
            select="datafield[@tag=504]">
            <dc:description>
    			<xsl:if test="subfield[@code='a']"><xsl:value-of select="subfield[@code='a']"/> </xsl:if>
            </dc:description>
        </xsl:for-each>
        <xsl:for-each
            select="datafield[@tag=505]">
            <dc:description>
    			<xsl:if test="subfield[@code='a']"> <xsl:value-of select="subfield[@code='a']"/> </xsl:if>
    			<xsl:if test="subfield[@code='t']"> <xsl:value-of select="subfield[@code='t']"/> </xsl:if>
    			<xsl:if test="subfield[@code='r']"> <xsl:value-of select="subfield[@code='r']"/> </xsl:if>				
            </dc:description>
        </xsl:for-each>		
        <xsl:for-each
            select="datafield[@tag=507]">
            <dc:description>
    			<xsl:if test="subfield[@code='a']"> <xsl:value-of select="subfield[@code='a']"/> </xsl:if>
    			<xsl:if test="subfield[@code='b']"> <xsl:value-of select="subfield[@code='b']"/> </xsl:if>				
            </dc:description>
        </xsl:for-each>
        <xsl:for-each
            select="datafield[@tag=508]">
            <dc:description>
    			<xsl:if test="subfield[@code='a']"> <xsl:value-of select="subfield[@code='a']"/> </xsl:if>
            </dc:description>
        </xsl:for-each>
        <xsl:for-each
            select="datafield[@tag=511]">
            <dc:description>
    			<xsl:if test="subfield[@code='a']"> <xsl:value-of select="subfield[@code='a']"/> </xsl:if>
            </dc:description>
        </xsl:for-each>
        <xsl:for-each
            select="datafield[@tag=520]">
            <dc:description>
    			<xsl:if test="subfield[@code='a']"> <xsl:value-of select="subfield[@code='a']"/> </xsl:if>
    			<xsl:if test="subfield[@code='b']"> <xsl:value-of select="subfield[@code='b']"/> </xsl:if>
    			<xsl:if test="subfield[@code='c']"> <xsl:value-of select="subfield[@code='c']"/> </xsl:if>
    			<xsl:if test="subfield[@code='u']"> &#160;<xsl:value-of select="subfield[@code='u']"/> </xsl:if>				
            </dc:description>
        </xsl:for-each>
        <xsl:for-each
            select="datafield[@tag=545]">
            <dc:description>
    			<xsl:if test="subfield[@code='a']"> <xsl:value-of select="subfield[@code='a']"/> </xsl:if>
    			<xsl:if test="subfield[@code='b']"> <xsl:value-of select="subfield[@code='b']"/> </xsl:if>
    			<xsl:if test="subfield[@code='u']">&#160;<xsl:value-of select="subfield[@code='u']"/> </xsl:if>				
            </dc:description>
        </xsl:for-each>
        <xsl:for-each
            select="datafield[@tag=561]">
            <dc:description>
    			<xsl:if test="subfield[@code='a']"> <xsl:value-of select="subfield[@code='a']"/> </xsl:if>
    			<xsl:if test="subfield[@code='u']">&#160;<xsl:value-of select="subfield[@code='u']"/> </xsl:if>				
            </dc:description>
        </xsl:for-each>
        <xsl:for-each
            select="datafield[@tag=563]">
            <dc:description>
    			<xsl:if test="subfield[@code='a']"> <xsl:value-of select="subfield[@code='a']"/> </xsl:if>
    			<xsl:if test="subfield[@code='u']">&#160;<xsl:value-of select="subfield[@code='u']"/> </xsl:if>				
            </dc:description>
        </xsl:for-each>
        <xsl:for-each
            select="datafield[@tag=581]">
            <dc:description>
     			<xsl:if test="subfield[@code='3']"> <xsl:value-of select="subfield[@code='3']"/> </xsl:if>	
    			<xsl:if test="subfield[@code='a']"> <xsl:value-of select="subfield[@code='a']"/> </xsl:if>		
            </dc:description>
        </xsl:for-each>
        <xsl:for-each
            select="datafield[@tag=585]">
            <dc:description>
     			<xsl:if test="subfield[@code='3']"> <xsl:value-of select="subfield[@code='3']"/> </xsl:if>	
    			<xsl:if test="subfield[@code='a']"> <xsl:value-of select="subfield[@code='a']"/> </xsl:if>		
            </dc:description>
        </xsl:for-each>
        <xsl:for-each
            select="datafield[@tag=586]">
            <dc:description>
     			<xsl:if test="subfield[@code='3']"> <xsl:value-of select="subfield[@code='3']"/> </xsl:if>	
    			<xsl:if test="subfield[@code='a']"> <xsl:value-of select="subfield[@code='a']"/> </xsl:if>		
            </dc:description>
        </xsl:for-each>				
        <xsl:for-each select="datafield[@tag=600]">
            <dc:subject>
				<xsl:if test="subfield[@code='a']"> <xsl:value-of select="subfield[@code='a']"/></xsl:if>
				<xsl:if test="subfield[@code='b']"> &#45; <xsl:value-of select="subfield[@code='b']"/></xsl:if>
				<xsl:if test="subfield[@code='c']"> &#45; <xsl:value-of select="subfield[@code='c']"/></xsl:if>
				<xsl:if test="subfield[@code='d']"> &#45; <xsl:value-of select="subfield[@code='d']"/></xsl:if>
				<xsl:if test="subfield[@code='e']"> &#45; <xsl:value-of select="subfield[@code='e']"/></xsl:if>
				<xsl:if test="subfield[@code='f']"> &#45; <xsl:value-of select="subfield[@code='f']"/></xsl:if>
				<xsl:if test="subfield[@code='g']"> &#45; <xsl:value-of select="subfield[@code='g']"/></xsl:if>
				<xsl:if test="subfield[@code='k']"> &#45; <xsl:value-of select="subfield[@code='k']"/></xsl:if>
				<xsl:if test="subfield[@code='j']"> &#45; <xsl:value-of select="subfield[@code='j']"/></xsl:if>
				<xsl:if test="subfield[@code='l']"> &#45; <xsl:value-of select="subfield[@code='l']"/></xsl:if>
				<xsl:if test="subfield[@code='m']"> &#45; <xsl:value-of select="subfield[@code='m']"/></xsl:if>
				<xsl:if test="subfield[@code='n']"> &#45; <xsl:value-of select="subfield[@code='n']"/></xsl:if>
				<xsl:if test="subfield[@code='o']"> &#45; <xsl:value-of select="subfield[@code='o']"/></xsl:if>
				<xsl:if test="subfield[@code='p']"> &#45; <xsl:value-of select="subfield[@code='p']"/></xsl:if>
				<xsl:if test="subfield[@code='q']"> &#45; <xsl:value-of select="subfield[@code='q']"/></xsl:if>
				<xsl:if test="subfield[@code='r']"> &#45; <xsl:value-of select="subfield[@code='r']"/></xsl:if>
				<xsl:if test="subfield[@code='s']"> &#45; <xsl:value-of select="subfield[@code='s']"/></xsl:if>
				<xsl:if test="subfield[@code='t']"> &#45; <xsl:value-of select="subfield[@code='t']"/></xsl:if>
				<xsl:if test="subfield[@code='u']"> &#45; <xsl:value-of select="subfield[@code='u']"/></xsl:if>
				<xsl:if test="subfield[@code='v']"> &#45; <xsl:value-of select="subfield[@code='v']"/></xsl:if>
				<xsl:if test="subfield[@code='x']"> &#45; <xsl:value-of select="subfield[@code='x']"/></xsl:if>
				<xsl:if test="subfield[@code='y']"> &#45; <xsl:value-of select="subfield[@code='y']"/></xsl:if>
				<xsl:if test="subfield[@code='z']"> &#45; <xsl:value-of select="subfield[@code='z']"/></xsl:if>		
            </dc:subject>
        </xsl:for-each>
        <xsl:for-each select="datafield[@tag=610]">
            <dc:subject>
				<xsl:if test="subfield[@code='a']"> <xsl:value-of select="subfield[@code='a']"/></xsl:if>
				<xsl:if test="subfield[@code='b']"> &#45; <xsl:value-of select="subfield[@code='b']"/></xsl:if>
				<xsl:if test="subfield[@code='c']"> &#45; <xsl:value-of select="subfield[@code='c']"/></xsl:if>
				<xsl:if test="subfield[@code='d']"> &#45; <xsl:value-of select="subfield[@code='d']"/></xsl:if>
				<xsl:if test="subfield[@code='e']"> &#45; <xsl:value-of select="subfield[@code='e']"/></xsl:if>
				<xsl:if test="subfield[@code='f']"> &#45; <xsl:value-of select="subfield[@code='f']"/></xsl:if>
				<xsl:if test="subfield[@code='g']"> &#45; <xsl:value-of select="subfield[@code='g']"/></xsl:if>
				<xsl:if test="subfield[@code='k']"> &#45; <xsl:value-of select="subfield[@code='k']"/></xsl:if>
				<xsl:if test="subfield[@code='j']"> &#45; <xsl:value-of select="subfield[@code='j']"/></xsl:if>
				<xsl:if test="subfield[@code='l']"> &#45; <xsl:value-of select="subfield[@code='l']"/></xsl:if>
				<xsl:if test="subfield[@code='m']"> &#45; <xsl:value-of select="subfield[@code='m']"/></xsl:if>
				<xsl:if test="subfield[@code='n']"> &#45; <xsl:value-of select="subfield[@code='n']"/></xsl:if>
				<xsl:if test="subfield[@code='o']"> &#45; <xsl:value-of select="subfield[@code='o']"/></xsl:if>
				<xsl:if test="subfield[@code='p']"> &#45; <xsl:value-of select="subfield[@code='p']"/></xsl:if>
				<xsl:if test="subfield[@code='q']"> &#45; <xsl:value-of select="subfield[@code='q']"/></xsl:if>
				<xsl:if test="subfield[@code='r']"> &#45; <xsl:value-of select="subfield[@code='r']"/></xsl:if>
				<xsl:if test="subfield[@code='s']"> &#45; <xsl:value-of select="subfield[@code='s']"/></xsl:if>
				<xsl:if test="subfield[@code='t']"> &#45; <xsl:value-of select="subfield[@code='t']"/></xsl:if>
				<xsl:if test="subfield[@code='u']"> &#45; <xsl:value-of select="subfield[@code='u']"/></xsl:if>
				<xsl:if test="subfield[@code='v']"> &#45; <xsl:value-of select="subfield[@code='v']"/></xsl:if>
				<xsl:if test="subfield[@code='x']"> &#45; <xsl:value-of select="subfield[@code='x']"/></xsl:if>
				<xsl:if test="subfield[@code='y']"> &#45; <xsl:value-of select="subfield[@code='y']"/></xsl:if>
				<xsl:if test="subfield[@code='z']"> &#45; <xsl:value-of select="subfield[@code='z']"/></xsl:if>		
            </dc:subject>
        </xsl:for-each>
        <xsl:for-each select="datafield[@tag=611]">
            <dc:subject>
				<xsl:if test="subfield[@code='a']"> <xsl:value-of select="subfield[@code='a']"/></xsl:if>
				<xsl:if test="subfield[@code='b']"> &#45; <xsl:value-of select="subfield[@code='b']"/></xsl:if>
				<xsl:if test="subfield[@code='c']"> &#45; <xsl:value-of select="subfield[@code='c']"/></xsl:if>
				<xsl:if test="subfield[@code='d']"> &#45; <xsl:value-of select="subfield[@code='d']"/></xsl:if>
				<xsl:if test="subfield[@code='e']"> &#45; <xsl:value-of select="subfield[@code='e']"/></xsl:if>
				<xsl:if test="subfield[@code='f']"> &#45; <xsl:value-of select="subfield[@code='f']"/></xsl:if>
				<xsl:if test="subfield[@code='g']"> &#45; <xsl:value-of select="subfield[@code='g']"/></xsl:if>
				<xsl:if test="subfield[@code='k']"> &#45; <xsl:value-of select="subfield[@code='k']"/></xsl:if>
				<xsl:if test="subfield[@code='j']"> &#45; <xsl:value-of select="subfield[@code='j']"/></xsl:if>
				<xsl:if test="subfield[@code='l']"> &#45; <xsl:value-of select="subfield[@code='l']"/></xsl:if>
				<xsl:if test="subfield[@code='m']"> &#45; <xsl:value-of select="subfield[@code='m']"/></xsl:if>
				<xsl:if test="subfield[@code='n']"> &#45; <xsl:value-of select="subfield[@code='n']"/></xsl:if>
				<xsl:if test="subfield[@code='o']"> &#45; <xsl:value-of select="subfield[@code='o']"/></xsl:if>
				<xsl:if test="subfield[@code='p']"> &#45; <xsl:value-of select="subfield[@code='p']"/></xsl:if>
				<xsl:if test="subfield[@code='q']"> &#45; <xsl:value-of select="subfield[@code='q']"/></xsl:if>
				<xsl:if test="subfield[@code='r']"> &#45; <xsl:value-of select="subfield[@code='r']"/></xsl:if>
				<xsl:if test="subfield[@code='s']"> &#45; <xsl:value-of select="subfield[@code='s']"/></xsl:if>
				<xsl:if test="subfield[@code='t']"> &#45; <xsl:value-of select="subfield[@code='t']"/></xsl:if>
				<xsl:if test="subfield[@code='u']"> &#45; <xsl:value-of select="subfield[@code='u']"/></xsl:if>
				<xsl:if test="subfield[@code='v']"> &#45; <xsl:value-of select="subfield[@code='v']"/></xsl:if>
				<xsl:if test="subfield[@code='x']"> &#45; <xsl:value-of select="subfield[@code='x']"/></xsl:if>
				<xsl:if test="subfield[@code='y']"> &#45; <xsl:value-of select="subfield[@code='y']"/></xsl:if>
				<xsl:if test="subfield[@code='z']"> &#45; <xsl:value-of select="subfield[@code='z']"/></xsl:if>		
            </dc:subject>
        </xsl:for-each>
        <xsl:for-each select="datafield[@tag=630]">
            <dc:subject>
				<xsl:if test="subfield[@code='a']"> <xsl:value-of select="subfield[@code='a']"/></xsl:if>
				<xsl:if test="subfield[@code='b']"> &#45; <xsl:value-of select="subfield[@code='b']"/></xsl:if>
				<xsl:if test="subfield[@code='c']"> &#45; <xsl:value-of select="subfield[@code='c']"/></xsl:if>
				<xsl:if test="subfield[@code='d']"> &#45; <xsl:value-of select="subfield[@code='d']"/></xsl:if>
				<xsl:if test="subfield[@code='e']"> &#45; <xsl:value-of select="subfield[@code='e']"/></xsl:if>
				<xsl:if test="subfield[@code='f']"> &#45; <xsl:value-of select="subfield[@code='f']"/></xsl:if>
				<xsl:if test="subfield[@code='g']"> &#45; <xsl:value-of select="subfield[@code='g']"/></xsl:if>
				<xsl:if test="subfield[@code='k']"> &#45; <xsl:value-of select="subfield[@code='k']"/></xsl:if>
				<xsl:if test="subfield[@code='j']"> &#45; <xsl:value-of select="subfield[@code='j']"/></xsl:if>
				<xsl:if test="subfield[@code='l']"> &#45; <xsl:value-of select="subfield[@code='l']"/></xsl:if>
				<xsl:if test="subfield[@code='m']"> &#45; <xsl:value-of select="subfield[@code='m']"/></xsl:if>
				<xsl:if test="subfield[@code='n']"> &#45; <xsl:value-of select="subfield[@code='n']"/></xsl:if>
				<xsl:if test="subfield[@code='o']"> &#45; <xsl:value-of select="subfield[@code='o']"/></xsl:if>
				<xsl:if test="subfield[@code='p']"> &#45; <xsl:value-of select="subfield[@code='p']"/></xsl:if>
				<xsl:if test="subfield[@code='q']"> &#45; <xsl:value-of select="subfield[@code='q']"/></xsl:if>
				<xsl:if test="subfield[@code='r']"> &#45; <xsl:value-of select="subfield[@code='r']"/></xsl:if>
				<xsl:if test="subfield[@code='s']"> &#45; <xsl:value-of select="subfield[@code='s']"/></xsl:if>
				<xsl:if test="subfield[@code='t']"> &#45; <xsl:value-of select="subfield[@code='t']"/></xsl:if>
				<xsl:if test="subfield[@code='u']"> &#45; <xsl:value-of select="subfield[@code='u']"/></xsl:if>
				<xsl:if test="subfield[@code='v']"> &#45; <xsl:value-of select="subfield[@code='v']"/></xsl:if>
				<xsl:if test="subfield[@code='x']"> &#45; <xsl:value-of select="subfield[@code='x']"/></xsl:if>
				<xsl:if test="subfield[@code='y']"> &#45; <xsl:value-of select="subfield[@code='y']"/></xsl:if>
				<xsl:if test="subfield[@code='z']"> &#45; <xsl:value-of select="subfield[@code='z']"/></xsl:if>		
            </dc:subject>
        </xsl:for-each>
        <xsl:for-each select="datafield[@tag=650]">
            <dc:subject>
				<xsl:if test="subfield[@code='a']"> <xsl:value-of select="subfield[@code='a']"/></xsl:if>
				<xsl:if test="subfield[@code='b']"> &#45; <xsl:value-of select="subfield[@code='b']"/></xsl:if>
				<xsl:if test="subfield[@code='c']"> &#45; <xsl:value-of select="subfield[@code='c']"/></xsl:if>
				<xsl:if test="subfield[@code='d']"> &#45; <xsl:value-of select="subfield[@code='d']"/></xsl:if>
				<xsl:if test="subfield[@code='e']"> &#45; <xsl:value-of select="subfield[@code='e']"/></xsl:if>
				<xsl:if test="subfield[@code='f']"> &#45; <xsl:value-of select="subfield[@code='f']"/></xsl:if>
				<xsl:if test="subfield[@code='g']"> &#45; <xsl:value-of select="subfield[@code='g']"/></xsl:if>
				<xsl:if test="subfield[@code='k']"> &#45; <xsl:value-of select="subfield[@code='k']"/></xsl:if>
				<xsl:if test="subfield[@code='j']"> &#45; <xsl:value-of select="subfield[@code='j']"/></xsl:if>
				<xsl:if test="subfield[@code='l']"> &#45; <xsl:value-of select="subfield[@code='l']"/></xsl:if>
				<xsl:if test="subfield[@code='m']"> &#45; <xsl:value-of select="subfield[@code='m']"/></xsl:if>
				<xsl:if test="subfield[@code='n']"> &#45; <xsl:value-of select="subfield[@code='n']"/></xsl:if>
				<xsl:if test="subfield[@code='o']"> &#45; <xsl:value-of select="subfield[@code='o']"/></xsl:if>
				<xsl:if test="subfield[@code='p']"> &#45; <xsl:value-of select="subfield[@code='p']"/></xsl:if>
				<xsl:if test="subfield[@code='q']"> &#45; <xsl:value-of select="subfield[@code='q']"/></xsl:if>
				<xsl:if test="subfield[@code='r']"> &#45; <xsl:value-of select="subfield[@code='r']"/></xsl:if>
				<xsl:if test="subfield[@code='s']"> &#45; <xsl:value-of select="subfield[@code='s']"/></xsl:if>
				<xsl:if test="subfield[@code='t']"> &#45; <xsl:value-of select="subfield[@code='t']"/></xsl:if>
				<xsl:if test="subfield[@code='u']"> &#45; <xsl:value-of select="subfield[@code='u']"/></xsl:if>
				<xsl:if test="subfield[@code='v']"> &#45; <xsl:value-of select="subfield[@code='v']"/></xsl:if>
				<xsl:if test="subfield[@code='x']"> &#45; <xsl:value-of select="subfield[@code='x']"/></xsl:if>
				<xsl:if test="subfield[@code='y']"> &#45; <xsl:value-of select="subfield[@code='y']"/></xsl:if>
				<xsl:if test="subfield[@code='z']"> &#45; <xsl:value-of select="subfield[@code='z']"/></xsl:if>		
            </dc:subject>
        </xsl:for-each>
        <xsl:for-each select="datafield[@tag=653]">
            <dc:subject>
				<xsl:if test="subfield[@code='a']"> <xsl:value-of select="subfield[@code='a']"/></xsl:if>
            </dc:subject>
        </xsl:for-each>
		<xsl:for-each select="datafield[@tag=518]">
            <dc:coverage>
				<xsl:if test="subfield[@code='a']"> <xsl:value-of select="subfield[@code='a']"/></xsl:if>
				<xsl:if test="subfield[@code='o']"> <xsl:value-of select="subfield[@code='o']"/></xsl:if>
				<xsl:if test="subfield[@code='p']"> <xsl:value-of select="subfield[@code='p']"/></xsl:if>
				<xsl:if test="subfield[@code='d']"> <xsl:value-of select="subfield[@code='d']"/></xsl:if>			
            </dc:coverage>
        </xsl:for-each>
        <xsl:for-each select="datafield[@tag=662]">
            <dcterms:spatial>
				<xsl:if test="subfield[@code='a']"> <xsl:value-of select="subfield[@code='a']"/></xsl:if>
				<xsl:if test="subfield[@code='b']"> &#45; <xsl:value-of select="subfield[@code='b']"/></xsl:if>
				<xsl:if test="subfield[@code='c']"> &#45; <xsl:value-of select="subfield[@code='c']"/></xsl:if>
				<xsl:if test="subfield[@code='d']"> &#45; <xsl:value-of select="subfield[@code='d']"/></xsl:if>
				<!--##<xsl:if test="subfield[@code='e']"> &#45; <xsl:value-of select="subfield[@code='e']"/></xsl:if>##-->
				<xsl:if test="subfield[@code='f']"> &#45; <xsl:value-of select="subfield[@code='f']"/></xsl:if>
				<xsl:if test="subfield[@code='g']"> &#45; <xsl:value-of select="subfield[@code='g']"/></xsl:if>
				<xsl:if test="subfield[@code='h']"> &#45; <xsl:value-of select="subfield[@code='h']"/></xsl:if>				
            </dcterms:spatial>
        </xsl:for-each>
        <xsl:for-each select="datafield[@tag=752]">
            <dcterms:spatial>
				<xsl:if test="subfield[@code='a']"> <xsl:value-of select="subfield[@code='a']"/></xsl:if>
				<xsl:if test="subfield[@code='b']"> <xsl:value-of select="subfield[@code='b']"/></xsl:if>
				<xsl:if test="subfield[@code='c']"> <xsl:value-of select="subfield[@code='c']"/></xsl:if>
				<xsl:if test="subfield[@code='d']"> <xsl:value-of select="subfield[@code='d']"/></xsl:if>
				<xsl:if test="subfield[@code='f']"> <xsl:value-of select="subfield[@code='f']"/></xsl:if>
				<xsl:if test="subfield[@code='g']"> <xsl:value-of select="subfield[@code='g']"/></xsl:if>
				<xsl:if test="subfield[@code='h']"> <xsl:value-of select="subfield[@code='h']"/></xsl:if>
            </dcterms:spatial>
        </xsl:for-each>
		<xsl:for-each select="datafield[@tag=651]">
            <dcterms:spatial>
				<xsl:if test="subfield[@code='a']"> <xsl:value-of select="subfield[@code='a']"/></xsl:if>
            </dcterms:spatial>
        </xsl:for-each>
		<xsl:for-each select="datafield[@tag=648]">
            <dcterms:temporal>
				<xsl:if test="subfield[@code='a']"> <xsl:value-of select="subfield[@code='a']"/></xsl:if>
				<xsl:if test="subfield[@code='y']"> &#45; <xsl:value-of select="subfield[@code='y']"/></xsl:if>				
            </dcterms:temporal>
        </xsl:for-each>		
        <xsl:for-each select="datafield[@tag=530]">
            <dcterms:hasFormat>
				<xsl:if test="subfield[@code='u']"> <xsl:value-of select="subfield[@code='u']"/></xsl:if>
            </dcterms:hasFormat>
        </xsl:for-each>
		<xsl:for-each select="datafield[@tag=530]">
            <dcterms:isFormatOf>
				<xsl:if test="subfield[@code='a']"> <xsl:value-of select="subfield[@code='a']"/></xsl:if>
				<xsl:if test="subfield[@code='b']"> <xsl:value-of select="subfield[@code='b']"/></xsl:if>
				<xsl:if test="subfield[@code='c']"> <xsl:value-of select="subfield[@code='c']"/></xsl:if>
				<xsl:if test="subfield[@code='d']"> <xsl:value-of select="subfield[@code='d']"/></xsl:if>
            </dcterms:isFormatOf>
        </xsl:for-each>
        <xsl:for-each
            select="datafield[@tag=773]">
            <dcterms:isPartOf>
				<xsl:if test="subfield[@code='t']"> <xsl:value-of select="subfield[@code='t']"/> </xsl:if>
				<xsl:if test="subfield[@code='b']"> <xsl:value-of select="subfield[@code='b']"/> </xsl:if>
				<xsl:if test="subfield[@code='d']"> <xsl:if test="substring(subfield[@code='b'], string-length(subfield[@code='b'])) != '.'"> </xsl:if>  <xsl:value-of select="subfield[@code='d']"/></xsl:if>
				<xsl:if test="subfield[@code='r']"> <xsl:value-of select="subfield[@code='r']"/> </xsl:if>
				<xsl:if test="subfield[@code='x']"> ISSN <xsl:value-of select="subfield[@code='x']"/> </xsl:if>
				<xsl:if test="subfield[@code='z']"> ISBN <xsl:value-of select="subfield[@code='z']"/> </xsl:if>
				<xsl:if test="subfield[@code='o']"> <xsl:value-of select="subfield[@code='o']"/> </xsl:if>
				<xsl:if test="subfield[@code='g']"> <xsl:value-of select="subfield[@code='g']"/> </xsl:if>
            </dcterms:isPartOf>
		</xsl:for-each>
        <xsl:for-each
            select="datafield[@tag=787]">
            <dcterms:references>
				<xsl:if test="subfield[@code='i']"> <xsl:value-of select="subfield[@code='b']"/> </xsl:if>
     			<xsl:if test="subfield[@code='t']"> <xsl:value-of select="subfield[@code='t']"/> </xsl:if>
				<xsl:if test="subfield[@code='a']"> <xsl:value-of select="subfield[@code='b']"/> </xsl:if>
				<xsl:if test="subfield[@code='d']"><xsl:value-of select="subfield[@code='b']"/> </xsl:if>
				<xsl:if test="subfield[@code='z']"> ISBN <xsl:value-of select="subfield[@code='z']"/> </xsl:if>
            </dcterms:references>
		</xsl:for-each>		
		<xsl:for-each
            select="datafield[@tag=760]|datafield[@tag=762]|datafield[@tag=765]|datafield[@tag=767]|datafield[@tag=770]|datafield[@tag=772]|datafield[@tag=774]|datafield[@tag=775]|datafield[@tag=776]|datafield[@tag=777]|datafield[@tag=780]|datafield[@tag=785]|datafield[@tag=786]|datafield[@tag=787]">
            <dc:relation>
				<xsl:if test="subfield[@code='a']"> <xsl:value-of select="subfield[@code='a']"/></xsl:if>
				<xsl:if test="subfield[@code='b']"> <xsl:value-of select="subfield[@code='b']"/></xsl:if>
				<xsl:if test="subfield[@code='c']"> <xsl:value-of select="subfield[@code='c']"/></xsl:if>
				<xsl:if test="subfield[@code='d']"> <xsl:value-of select="subfield[@code='d']"/></xsl:if>
				<xsl:if test="subfield[@code='x']"> <xsl:value-of select="subfield[@code='x']"/></xsl:if>
				<xsl:if test="subfield[@code='z']"> <xsl:value-of select="subfield[@code='z']"/></xsl:if>
				<xsl:if test="subfield[@code='o']"> <xsl:value-of select="subfield[@code='o']"/></xsl:if>
				<xsl:if test="subfield[@code='g']"> <xsl:value-of select="subfield[@code='g']"/></xsl:if>
            </dc:relation>
        </xsl:for-each>	
        <xsl:for-each select="datafield[@tag=506]">
            <dc:rights>
                <xsl:value-of select="subfield[@code='a']" />
            </dc:rights>
        </xsl:for-each>
        <xsl:for-each select="datafield[@tag=540]">
            <dc:rights>
                <xsl:value-of select="subfield[@code='a']" />
            </dc:rights>
        </xsl:for-each>
        <!--##</dc metadata>##-->
        <!--##<europeana metadata>##-->
        <europeana:dataProvider><xsl:value-of select="$data_provider"/></europeana:dataProvider>		
        <europeana:provider><xsl:value-of select="$provider"/></europeana:provider>
        <europeana:type>
            <xsl:if test="$leader6='d' or $leader6='e' or $leader6='f' or $leader6='k' or $leader6='p'">IMAGE</xsl:if>
            <xsl:if test="$leader6='a' or $leader6='c' or $leader6='t'">TEXT</xsl:if>
            <xsl:if test="$leader6='i' or $leader6='j'">SOUND</xsl:if>
            <xsl:if test="$leader6='g'">VIDEO</xsl:if>
            <!--##<xsl:if test="$leader6='r'">three dimensional object</xsl:if>##-->
            <!--##<xsl:if test="$leader6='m'">software, multimedia</xsl:if>##-->
            <!--##<xsl:if test="$leader6='p'">mixed material</xsl:if>##-->
        </europeana:type>
        <xsl:for-each select="datafield[@tag=100]|datafield[@tag=700]">
			<europeana:unstored>
              <xsl:if test="subfield[@code='e']"> <xsl:value-of select="subfield[@code='a']"/></xsl:if>
              <xsl:if test="subfield[@code='e']"> <xsl:value-of select="subfield[@code='b']"/></xsl:if>
              <xsl:if test="subfield[@code='e']"> <xsl:value-of select="subfield[@code='n']"/></xsl:if>
              <xsl:if test="subfield[@code='e']"> <xsl:value-of select="subfield[@code='c']"/></xsl:if>
              <xsl:if test="subfield[@code='e']"> <xsl:value-of select="subfield[@code='d']"/></xsl:if>	  
              <xsl:if test="subfield[@code='e']"> <xsl:value-of select="subfield[@code='e']"/></xsl:if>
            </europeana:unstored>
        </xsl:for-each>
		<xsl:for-each select="datafield[@tag=110]|datafield[@tag=111]|datafield[@tag=710]|datafield[@tag=711]|datafield[@tag=720]">
			<europeana:unstored>
              <xsl:if test="subfield[@code='a']"> <xsl:value-of select="subfield[@code='a']"/></xsl:if>
              <xsl:if test="subfield[@code='b']"> <xsl:value-of select="subfield[@code='b']"/></xsl:if>
              <xsl:if test="subfield[@code='n']"> <xsl:value-of select="subfield[@code='n']"/></xsl:if>
              <xsl:if test="subfield[@code='c']"> <xsl:value-of select="subfield[@code='c']"/></xsl:if>
              <xsl:if test="subfield[@code='d']"> <xsl:value-of select="subfield[@code='d']"/></xsl:if>
              <xsl:if test="subfield[@code='e']"> <xsl:value-of select="subfield[@code='e']"/></xsl:if>
            </europeana:unstored>
        </xsl:for-each>		
        <xsl:if test="controlfield[@tag='001'] and $record_address != ''">
            <europeana:isShownAt>
                <xsl:call-template name="replace_all"><xsl:with-param name="str" select="$record_address"/><xsl:with-param name="src" select="'[001]'"/><xsl:with-param name="dest" select="controlfield[@tag='001']"/></xsl:call-template>
            </europeana:isShownAt>
        </xsl:if>            
		<xsl:for-each select="datafield[@tag=856]">
            <europeana:isShownBy>
				<xsl:if test="subfield[@code='u']"> <xsl:value-of select="subfield[@code='u']"/></xsl:if>
            </europeana:isShownBy>
        </xsl:for-each>
		<!--##If a suitable image can be provided for image generation##-->
		<!--##<europeana:object></europeana:object>##-->
		<!--##Default: no other restrictions in 506 or 540##-->
		<!--##<europeana:rights>http://www.europeana.eu/rights/rr-f/</europeana:rights>##-->
		<!--##2011-08-30: if same as for ETravel collection from Finland-->
		<europeana:rights>http://creativecommons.org/publicdomain/mark/1.0/</europeana:rights>
		<!--##Default: not in use##-->
		<!--##<europeana:unstored></europeana:unstored>##-->
    </xsl:template>
    
    <xsl:template name="replace_all">
        <xsl:param name="str"/>
        <xsl:param name="src"/>
        <xsl:param name="dest"/>
        <xsl:choose>
            <xsl:when test="contains($str, $src)">
                <xsl:value-of select="concat(substring-before($str, $src), $dest)"/>
                <xsl:call-template name="replace_all">
                  <xsl:with-param name="str" select="substring-after($str, $src)"/>
                  <xsl:with-param name="src" select="$src"/>
                  <xsl:with-param name="dest" select="$dest"/>
                </xsl:call-template>
            </xsl:when>
            <xsl:otherwise>
                <xsl:value-of select="$str"/>
            </xsl:otherwise>
        </xsl:choose>
    </xsl:template>

    <xsl:template name="format_date">
        <xsl:param name="date"/>
        <xsl:variable name="date2"><xsl:call-template name="replace_all">
                <xsl:with-param name="str" select="$date"/>
                <xsl:with-param name="src" select="'-'"/>
                <xsl:with-param name="dest" select="''"/>
            </xsl:call-template>
        </xsl:variable>
        <xsl:value-of select="substring($date2, 1, 4)"/><xsl:if test="string-length($date2) > 4">-<xsl:value-of select="substring($date2, 5, 2)"/></xsl:if><xsl:if test="string-length($date2) > 6">-<xsl:value-of select="substring($date2, 7, 2)"/></xsl:if>
    </xsl:template>
    
</xsl:stylesheet>
