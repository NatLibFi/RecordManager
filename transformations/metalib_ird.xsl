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
         <xsl:if test="(local-name() != 'datafield' and local-name() != 'controlfield') or string(number(@tag)) != 'NaN'">
             <xsl:element name="{local-name()}">
                 <xsl:apply-templates select="@*|node()"/>
             </xsl:element>
         </xsl:if>
     </xsl:template>
     
     <xsl:template match="@*">
         <xsl:attribute name="{local-name()}">
           <xsl:value-of select="."/>
         </xsl:attribute>
     </xsl:template>
     
</xsl:stylesheet>
