<xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform">
  <xsl:output method="xml" indent="no"/>

  <xsl:template name="validatetextlang">
    <xsl:param name="lang"/>

    <xsl:choose>

      <xsl:when test="$lang='English'">
        <xsl:value-of select="'eng'"/>
      </xsl:when>

      <xsl:when test="$lang='German'">
        <xsl:value-of select="'deu'"/>
      </xsl:when>

      <xsl:when test="$lang='Swedish'">
        <xsl:value-of select="'swe'"/>
      </xsl:when>
 
      <xsl:otherwise>
        <xsl:value-of select="$lang"/>
      </xsl:otherwise>

    </xsl:choose>

  </xsl:template>

</xsl:stylesheet>
