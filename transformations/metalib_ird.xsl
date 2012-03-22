<xsl:stylesheet version="1.0" 
xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
xmlns:marc="http://www.loc.gov/MARC21/slim"
exclude-result-prefixes="marc"
>
     <xsl:output method="xml" indent="no"/>
    
     <xsl:template match="/|comment()|processing-instruction()">
         <xsl:copy>
           <xsl:apply-templates/>
         </xsl:copy>
     </xsl:template>
     
     <xsl:template match="*">
         <xsl:if test="local-name(..) = 'record' and count(preceding-sibling::*) = 0">
<leader>     nai a22     ua 4500</leader>
         </xsl:if>
         <xsl:if test="local-name(..) = 'record' and count(following-sibling::*) = 0">
<datafield tag="977">
  <subfield code="a">Database</subfield>
</datafield>
         </xsl:if>    
         <xsl:if test="(local-name() != 'datafield' and local-name() != 'controlfield') or @tag = 'PXY' or (string(number(@tag)) != 'NaN')">
             <xsl:choose>
                <xsl:when test="local-name() = 'datafield' and @tag = '591'">
                </xsl:when>
                <xsl:when test="local-name() = 'datafield' and @tag = '856' and @ind1 = '4'">
                    <xsl:if test="@ind2 = '1'">
<datafield tag="856">
  <subfield code="u"><xsl:value-of select="subfield"></xsl:value-of></subfield>
  <subfield code="y">Database Interface</subfield>
</datafield>
                    </xsl:if>
                    <xsl:if test="@ind2 = '9'">
<datafield tag="856">
  <subfield code="u"><xsl:value-of select="subfield"></xsl:value-of></subfield>
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
     </xsl:template>
     
     <xsl:template match="@*">
         <xsl:attribute name="{local-name()}">
           <xsl:value-of select="."/>
         </xsl:attribute>
     </xsl:template>
     
</xsl:stylesheet>
