<xsl:stylesheet version="1.0" 
xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
>
	<xsl:output method="xml" indent="no"/>

	<xsl:template match="node()|@*">
	  <xsl:copy>
	   <xsl:apply-templates select="node()|@*"/>
	  </xsl:copy>
	 </xsl:template>
	
    <xsl:template match="//lido/descriptiveMetadata/objectClassificationWrap/objectWorkTypeWrap/objectWorkType/term">
      <xsl:choose>
         <xsl:when test="text() = 'Kuva'"><term>Image</term></xsl:when>
         <xsl:when test="text() = 'Esine'"><term>PhysicalObject</term></xsl:when>
         <xsl:otherwise><term><xsl:value-of select='text()'/></term></xsl:otherwise>
      </xsl:choose>
    </xsl:template>
	 
</xsl:stylesheet>
