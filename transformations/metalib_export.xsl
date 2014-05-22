<xsl:stylesheet version="1.0" 
xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
xmlns:marc="http://www.loc.gov/standards/marcxml/schema/MARC21slim.xsd"
exclude-result-prefixes="marc"
>
  <xsl:output method="xml" indent="no"/>

  <xsl:template match="/">
    <collection>
    <xsl:for-each select=".//marc:knowledge_unit">
      <xsl:if test="./marc:record">
        <record>
	      <xsl:apply-templates select="./marc:record"/>
	      <xsl:apply-templates select="./marc:category"/>
					<datafield tag="977">
					  <subfield code="a">Database</subfield>
					</datafield>
			  </record>
      </xsl:if>
    </xsl:for-each>
    </collection>
  </xsl:template>
  
  <xsl:template match="//marc:record">
    <leader>     nai a22     ua 4500</leader>
    <xsl:for-each select=".//marc:controlfield|.//marc:datafield">
         <xsl:if test="@tag = 'TAR' or @tag = 'PXY' or (string(number(@tag)) != 'NaN')">
             <xsl:choose>
                <xsl:when test="local-name() = 'datafield' and @tag = '591'">
                </xsl:when>
                <xsl:when test="local-name() = 'datafield' and @tag = '856' and @ind1 = '4'">
                    <xsl:if test="@ind2 = '1'">
		<datafield tag="856">
		  <subfield code="u"><xsl:value-of select="marc:subfield"></xsl:value-of></subfield>
		  <subfield code="y">Database Interface</subfield>
		</datafield>
                    </xsl:if>
                    <xsl:if test="@ind2 = '9'">
		<datafield tag="856">
		  <subfield code="u"><xsl:value-of select="marc:subfield"></xsl:value-of></subfield>
		  <subfield code="y">Database Guide</subfield>
		</datafield>
                    </xsl:if>
                </xsl:when>
                <xsl:otherwise>
                    <xsl:element name="{local-name()}">
                        <xsl:apply-templates select="@*|node()"/>
                    </xsl:element>
                </xsl:otherwise>
             </xsl:choose>
             <xsl:if test="local-name() = 'controlfield' and @tag = '001'">

    <controlfield tag="007">cr||||||||||||</controlfield>
                 <xsl:variable name="createdate"><xsl:value-of select="substring(concat(//marc:datafield[@tag='CAT'][1]/marc:subfield[@code='c'], '        '), 3, 6)"/></xsl:variable>
    <controlfield tag="008"><xsl:value-of select="$createdate"/>uuuuuuuuuxx|||||o|||||||||||||||</controlfield>
             </xsl:if>
         </xsl:if>
    
    </xsl:for-each>
  </xsl:template>

  <xsl:template match="@*">
      <xsl:attribute name="{local-name()}">
        <xsl:value-of select="."/>
      </xsl:attribute>
  </xsl:template>

  <xsl:template match="//marc:subfield">
      <subfield code="{@code}"><xsl:value-of select="."/></subfield>
  </xsl:template>
  
  <xsl:template match="//marc:category">
    <xsl:for-each select=".//marc:main">
    <datafield tag="976">
      <subfield code="a">
        <xsl:choose>
          <xsl:when test="substring(., 1, 2) = '- '">
            <xsl:value-of select="substring(., 3)"/>
          </xsl:when>
          <xsl:otherwise>
            <xsl:value-of select="."/>
          </xsl:otherwise>
        </xsl:choose>
      </subfield>
      <xsl:if test="local-name(following-sibling::*[1]) = 'sub'">
      <subfield code="b">
        <xsl:value-of select="following-sibling::*[1]"/>
      </subfield>
      </xsl:if>
    </datafield>
    </xsl:for-each>
  </xsl:template>

</xsl:stylesheet>
