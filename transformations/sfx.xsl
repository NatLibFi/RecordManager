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
         <xsl:element name="{local-name()}">
              <xsl:apply-templates select="@*|node()"/>
          </xsl:element>
         <xsl:if test="local-name() = 'leader'">
<controlfield tag="007">cr</controlfield>
         </xsl:if>
     </xsl:template>
     
     <xsl:template match="@*">
         <xsl:attribute name="{local-name()}">
           <xsl:value-of select="."/>
         </xsl:attribute>
     </xsl:template>
     
</xsl:stylesheet>
