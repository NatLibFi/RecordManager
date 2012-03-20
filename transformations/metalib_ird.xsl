<xsl:stylesheet version="1.0" 
xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
>
     <xsl:output method="xml" indent="no"/>
    
     <xsl:template match="/|comment()|processing-instruction()">
         <xsl:copy>
           <xsl:apply-templates/>
         </xsl:copy>
     </xsl:template>
     
     <xsl:template match="*">
         <xsl:if test="(local-name() != 'datafield' and local-name() != 'controlfield') or @tag = 'PXY' or (string(number(@tag)) != 'NaN')">
             <xsl:choose>
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
         </xsl:if>
     </xsl:template>
     
     <xsl:template match="@*">
         <xsl:attribute name="{local-name()}">
           <xsl:value-of select="."/>
         </xsl:attribute>
     </xsl:template>
     
</xsl:stylesheet>
