<xsl:stylesheet version="1.0" 
  xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" 
  xmlns:europeana="http://www.europeana.eu/schemas/ese/" xsi:schemaLocation="http://www.europeana.eu/schemas/ese/ http://www.europeana.eu/schemas/ese/ESE-V3.4.xsd"
>
  <xsl:output method="xml" indent="yes"/>

	<xsl:template match="/ | @* | node()">
		<xsl:copy>
  		<xsl:apply-templates select="@* | node()" />
		</xsl:copy>
	</xsl:template>
  
  <xsl:template match="europeana:provider">
    <europeana:provider><xsl:value-of select="$provider"/></europeana:provider>
  </xsl:template>
   
</xsl:stylesheet>
